<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMpesaB2CCallback;
use App\Jobs\ProcessMpesaTopUpCallback;
use App\Models\MpesaTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    /**
     * Handle the STK Push callback from Safaricom.
     *
     * CRITICAL constraints:
     *   1. Must return 200 OK to Safaricom within 5 seconds
     *      or they will retry (potentially causing double-processing)
     *   2. Must NEVER do wallet operations directly here
     *   3. All processing happens in ProcessMpesaTopUpCallback job
     *
     * This route is PUBLIC — no auth:sanctum middleware.
     * Safaricom cannot authenticate as a NiaLink user.
     * Route defined outside all auth middleware groups in api.php.
     */
    public function stkCallback(Request $request): JsonResponse
    {
        Log::channel('mpesa')->info('STK Push callback received', [
            'payload' => $request->all(),
            'ip'      => $request->ip(),
        ]);

        $payload = $request->all();

        // Basic structure validation — malformed payloads are discarded
        if (! isset($payload['Body']['stkCallback']['CheckoutRequestID'])) {
            Log::channel('mpesa')->warning('STK callback missing CheckoutRequestID', $payload);
            return $this->safaricomAck();
        }

        $checkoutRequestId = $payload['Body']['stkCallback']['CheckoutRequestID'];

        // Idempotency check — has this callback already been processed?
        $mpesaTransaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)
            ->first();

        if (! $mpesaTransaction) {
            Log::channel('mpesa')->warning('STK callback for unknown CheckoutRequestID', [
                'checkout_request_id' => $checkoutRequestId,
            ]);
            return $this->safaricomAck();
        }

        if ($mpesaTransaction->isCompleted() || $mpesaTransaction->isFailed()) {
            // Already processed — Safaricom is retrying. Acknowledge and ignore.
            Log::channel('mpesa')->info('STK callback already processed — ignoring retry', [
                'checkout_request_id' => $checkoutRequestId,
                'status'              => $mpesaTransaction->status,
            ]);
            return $this->safaricomAck();
        }

        // Dispatch to queue — returns immediately to Safaricom
        ProcessMpesaTopUpCallback::dispatch($payload);

        return $this->safaricomAck();
    }

    /**
     * Handle the B2C result callback from Safaricom.
     * Called when a withdrawal or settlement payout is processed.
     *
     * Same constraints as stkCallback — return 200 immediately,
     * process in queue job.
     */
    public function b2cResult(Request $request): JsonResponse
    {
        Log::channel('mpesa')->info('B2C result callback received', [
            'payload' => $request->all(),
            'ip'      => $request->ip(),
        ]);

        ProcessMpesaB2CCallback::dispatch($request->all());

        return $this->safaricomAck();
    }

    /**
     * Handle the B2C timeout callback from Safaricom.
     * Called when NiaLink's result URL is unreachable within Safaricom's window.
     *
     * This is a system-level alert — not triggered by the user.
     * We log it and treat it as a failed payout so the refund job can run.
     */
    public function b2cTimeout(Request $request): JsonResponse
    {
        Log::channel('mpesa')->critical('B2C timeout callback received — payout may be stuck', [
            'payload' => $request->all(),
            'ip'      => $request->ip(),
        ]);

        // Dispatch the same B2C callback job — it will handle the timeout
        // result code and trigger a refund if needed
        ProcessMpesaB2CCallback::dispatch($request->all());

        return $this->safaricomAck();
    }

    /**
     * The exact response Safaricom expects for all callbacks.
     * Any non-200 or unexpected body causes Safaricom to retry.
     */
    private function safaricomAck(): JsonResponse
    {
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }
}
