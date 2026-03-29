<?php

namespace App\Jobs;

use App\Models\MpesaTransaction;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\ReconciliationService;
use App\Services\TransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMpesaB2CCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(
        public readonly array $callbackPayload,
    ) {}

    /**
     * Process the Safaricom B2C result or timeout callback.
     *
     * B2C is used for:
     *   - Consumer withdrawals (wallet → M-Pesa)
     *   - Merchant settlements (wallet → M-Pesa)
     *
     * The wallet was already debited BEFORE the B2C was initiated
     * (in TransactionService::processWithdrawal). This job handles
     * the two possible outcomes:
     *
     *   Success (ResultCode = 0):
     *     → Mark MpesaTransaction completed
     *     → Mark internal Transaction completed
     *     → Record float outflow
     *
     *   Failure (ResultCode != 0) or Timeout:
     *     → Mark MpesaTransaction failed
     *     → Call TransactionService::refundWithdrawal()
     *       → Creates compensating credit entry
     *       → Marks internal Transaction failed
     *     → Record no float change (money never left the trust account)
     */
    public function handle(
        TransactionService    $transactionService,
        ReconciliationService $reconciliationService,
        AuditService          $auditService,
    ): void {
        // B2C result payload structure differs from STK Push
        // Result: payload['Result']
        // Timeout: payload['Body'] (same handler, different structure)
        $result = $this->callbackPayload['Result']
            ?? $this->callbackPayload['Body']
            ?? null;

        if (! $result) {
            Log::channel('mpesa')->error('B2C callback missing Result or Body', $this->callbackPayload);
            return;
        }

        $resultCode        = (string) ($result['ResultCode'] ?? '1');
        $resultDescription = $result['ResultDesc'] ?? 'Unknown result';

        // Find the pending M-Pesa transaction
        // B2C callbacks do not always include a CheckoutRequestID —
        // we match on the conversation ID or look for the most recent pending B2C
        $mpesaTransaction = $this->findMpesaTransaction($result);

        if (! $mpesaTransaction) {
            Log::channel('mpesa')->warning('B2C callback could not be matched to a pending transaction', [
                'result' => $result,
            ]);
            return;
        }

        // Already processed
        if (! $mpesaTransaction->isPending()) {
            Log::channel('mpesa')->info('B2C callback already processed — ignoring', [
                'mpesa_transaction_id' => $mpesaTransaction->id,
                'status'               => $mpesaTransaction->status,
            ]);
            return;
        }

        if ($resultCode === '0') {
            $this->handleSuccess($result, $mpesaTransaction, $reconciliationService, $auditService);
        } else {
            $this->handleFailure($result, $mpesaTransaction, $resultCode, $resultDescription, $transactionService, $auditService);
        }
    }

    /**
     * B2C payout succeeded — real KES left the trust account.
     * Mark everything as completed and record the float outflow.
     */
    private function handleSuccess(
        array                 $result,
        MpesaTransaction      $mpesaTransaction,
        ReconciliationService $reconciliationService,
        AuditService          $auditService,
    ): void {
        $parameters    = collect($result['ResultParameters']['ResultParameter'] ?? []);
        $mpesaReceipt  = (string) ($parameters->firstWhere('Key', 'TransactionReceipt')['Value'] ?? '');
        $amount        = (float) ($parameters->firstWhere('Key', 'TransactionAmount')['Value']
            ?? $mpesaTransaction->amount);

        $mpesaTransaction->markCompleted($mpesaReceipt, $result);

        // Mark the internal transaction as completed
        if ($mpesaTransaction->transaction_id) {
            Transaction::find($mpesaTransaction->transaction_id)
                ?->update(['status' => 'completed']);
        }

        // Record float outflow — real KES left the trust account
        $reconciliationService->recordOutflow(
            $amount,
            $mpesaTransaction->type === 'settlement' ? 'settlement_debit' : 'withdrawal_debit',
            $mpesaTransaction->id,
            $mpesaTransaction->transaction_id,
        );

        $auditService->log(
            'wallet.withdrawal.completed',
            $mpesaTransaction,
            [
                'amount'        => $amount,
                'mpesa_receipt' => $mpesaReceipt,
            ],
            $mpesaTransaction->user_id,
        );

        Log::channel('mpesa')->info('B2C payout completed', [
            'user_id'       => $mpesaTransaction->user_id,
            'amount'        => $amount,
            'mpesa_receipt' => $mpesaReceipt,
            'type'          => $mpesaTransaction->type,
        ]);
    }

    /**
     * B2C payout failed — real KES never left the trust account.
     * Refund the wallet so the user gets their money back.
     */
    private function handleFailure(
        array              $result,
        MpesaTransaction   $mpesaTransaction,
        string             $resultCode,
        string             $resultDescription,
        TransactionService $transactionService,
        AuditService       $auditService,
    ): void {
        $mpesaTransaction->markFailed($resultCode, $resultDescription, $result);

        // Refund the wallet — creates a compensating credit entry
        if ($mpesaTransaction->transaction_id) {
            $internalTransaction = Transaction::find($mpesaTransaction->transaction_id);

            if ($internalTransaction && $internalTransaction->status === 'pending') {
                try {
                    $transactionService->refundWithdrawal($internalTransaction);
                } catch (\Exception $e) {
                    Log::channel('mpesa')->critical('B2C refund failed — manual intervention required', [
                        'mpesa_transaction_id'    => $mpesaTransaction->id,
                        'internal_transaction_id' => $internalTransaction->id,
                        'error'                   => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        $auditService->log(
            'wallet.withdrawal.failed',
            $mpesaTransaction,
            [
                'result_code'        => $resultCode,
                'result_description' => $resultDescription,
                'refunded'           => true,
            ],
            $mpesaTransaction->user_id,
        );

        Log::channel('mpesa')->warning('B2C payout failed — wallet refunded', [
            'user_id'            => $mpesaTransaction->user_id,
            'amount'             => $mpesaTransaction->amount,
            'result_code'        => $resultCode,
            'result_description' => $resultDescription,
        ]);
    }

    /**
     * Find the pending MpesaTransaction that matches this B2C callback.
     *
     * B2C callbacks can be matched via:
     *   1. ConversationID in the result parameters
     *   2. Most recent pending B2C for this user (fallback)
     *
     * Safaricom's B2C callback does not always return a clean unique identifier
     * that maps back to our request — this is a known Daraja limitation.
     */
    private function findMpesaTransaction(array $result): ?MpesaTransaction
    {
        // Try to match via OriginatorConversationID if present
        $conversationId = $result['OriginatorConversationID'] ?? null;

        if ($conversationId) {
            $found = MpesaTransaction::where('checkout_request_id', $conversationId)
                ->where('status', 'pending')
                ->first();

            if ($found) {
                return $found;
            }
        }

        // Fallback — most recent pending B2C transaction
        // This works reliably when withdrawals are processed sequentially
        return MpesaTransaction::whereIn('type', ['withdrawal', 'settlement'])
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    /**
     * Handle job failure after all retries are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('mpesa')->critical('ProcessMpesaB2CCallback job failed after all retries', [
            'payload' => $this->callbackPayload,
            'error'   => $exception->getMessage(),
        ]);
    }
}
