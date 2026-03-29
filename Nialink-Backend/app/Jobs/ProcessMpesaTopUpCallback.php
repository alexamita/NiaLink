<?php

namespace App\Jobs;

use App\Models\MpesaTransaction;
use App\Services\AuditService;
use App\Services\ReconciliationService;
use App\Services\TransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMpesaTopUpCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry up to 3 times if the job fails.
     * Safaricom callbacks are idempotent — retrying is safe because:
     *   1. MpesaTransaction status check prevents double-processing
     *   2. mpesa_receipt unique constraint prevents double-crediting
     */
    public int $tries = 3;

    /**
     * Wait 60 seconds between retry attempts.
     * Gives the DB time to recover if a transient failure occurred.
     */
    public int $backoff = 60;

    /**
     * Discard the job if it is still in the queue after 10 minutes.
     * By then the STK Push window has long passed.
     */
    public int $timeout = 600;

    public function __construct(
        public readonly array $callbackPayload,
    ) {}

    /**
     * Process the Safaricom STK Push callback.
     *
     * What this does:
     *   1. Extracts CheckoutRequestID and ResultCode from payload
     *   2. Finds the pending MpesaTransaction row
     *   3. If ResultCode = 0 (success):
     *      a. Updates MpesaTransaction to completed
     *      b. Creates internal Transaction via TransactionService
     *      c. Credits consumer wallet
     *      d. Records float inflow via ReconciliationService
     *   4. If ResultCode != 0 (failure or cancellation):
     *      a. Updates MpesaTransaction to failed
     *      b. No wallet change — nothing was collected
     *
     * Idempotency:
     *   - Status check: skips if already completed or failed
     *   - mpesa_receipt unique constraint: DB-level double-credit prevention
     */
    public function handle(
        TransactionService   $transactionService,
        ReconciliationService $reconciliationService,
        AuditService         $auditService,
    ): void {
        $callback = $this->callbackPayload['Body']['stkCallback'] ?? null;

        if (! $callback) {
            Log::channel('mpesa')->error('STK callback missing Body.stkCallback', $this->callbackPayload);
            return;
        }

        $checkoutRequestId = $callback['CheckoutRequestID'];
        $resultCode        = (string) $callback['ResultCode'];

        // Find the pending M-Pesa transaction
        $mpesaTransaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)
            ->where('status', 'pending')
            ->first();

        // Already processed — idempotency guard
        if (! $mpesaTransaction) {
            Log::channel('mpesa')->info('STK callback already processed or not found', [
                'checkout_request_id' => $checkoutRequestId,
            ]);
            return;
        }

        if ($resultCode === '0') {
            $this->handleSuccess($callback, $mpesaTransaction, $transactionService, $reconciliationService, $auditService);
        } else {
            $this->handleFailure($callback, $mpesaTransaction, $resultCode, $auditService);
        }
    }

    /**
     * Handle a successful STK Push callback.
     * Credits the consumer wallet and records the float inflow.
     */
    private function handleSuccess(
        array                $callback,
        MpesaTransaction     $mpesaTransaction,
        TransactionService   $transactionService,
        ReconciliationService $reconciliationService,
        AuditService         $auditService,
    ): void {
        // Extract values from Safaricom's metadata array
        // Format: [['Name' => 'Amount', 'Value' => 500], ...]
        $metadata      = collect($callback['CallbackMetadata']['Item'] ?? []);
        $amount        = (float) $metadata->firstWhere('Name', 'Amount')['Value'];
        $mpesaReceipt  = (string) $metadata->firstWhere('Name', 'MpesaReceiptNumber')['Value'];
        $phone         = (string) ($metadata->firstWhere('Name', 'PhoneNumber')['Value'] ?? $mpesaTransaction->phone_number);

        try {
            // Mark the M-Pesa transaction as completed
            $mpesaTransaction->markCompleted($mpesaReceipt, $callback);

            // Create the internal transaction and credit the wallet
            $transaction = $transactionService->processTopUp(
                $mpesaTransaction->user,
                $amount,
                $mpesaReceipt,
            );

            // Link M-Pesa record to the internal transaction
            $mpesaTransaction->update(['transaction_id' => $transaction->id]);

            // Record the float inflow — trust account just received real KES
            $reconciliationService->recordInflow(
                $amount,
                $mpesaTransaction->id,
                $transaction->id,
            );

            $auditService->log(
                'wallet.topup.completed',
                $transaction,
                [
                    'amount'        => $amount,
                    'mpesa_receipt' => $mpesaReceipt,
                    'phone'         => $phone,
                ],
                $mpesaTransaction->user_id,
            );

            Log::channel('mpesa')->info('STK Push top-up completed', [
                'user_id'       => $mpesaTransaction->user_id,
                'amount'        => $amount,
                'mpesa_receipt' => $mpesaReceipt,
            ]);

        } catch (\Exception $e) {
            Log::channel('mpesa')->critical('STK top-up processing failed after successful callback', [
                'checkout_request_id' => $mpesaTransaction->checkout_request_id,
                'mpesa_receipt'       => $mpesaReceipt,
                'error'               => $e->getMessage(),
            ]);

            // Re-throw so the job retries
            throw $e;
        }
    }

    /**
     * Handle a failed or cancelled STK Push callback.
     * No money was collected — no wallet change needed.
     */
    private function handleFailure(
        array            $callback,
        MpesaTransaction $mpesaTransaction,
        string           $resultCode,
        AuditService     $auditService,
    ): void {
        $resultDescription = $callback['ResultDesc'] ?? 'Unknown failure';

        $mpesaTransaction->markFailed($resultCode, $resultDescription, $callback);

        $auditService->log(
            'wallet.topup.failed',
            $mpesaTransaction,
            [
                'result_code'        => $resultCode,
                'result_description' => $resultDescription,
            ],
            $mpesaTransaction->user_id,
        );

        Log::channel('mpesa')->info('STK Push top-up failed', [
            'user_id'            => $mpesaTransaction->user_id,
            'result_code'        => $resultCode,
            'result_description' => $resultDescription,
        ]);
    }

    /**
     * Handle job failure after all retries are exhausted.
     * Logs at critical level — ops team must investigate manually.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('mpesa')->critical('ProcessMpesaTopUpCallback job failed after all retries', [
            'payload' => $this->callbackPayload,
            'error'   => $exception->getMessage(),
        ]);
    }
}
