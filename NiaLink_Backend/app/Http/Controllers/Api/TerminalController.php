<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;

/**
 * This endpoint allows the merchant's hardware to check the status of a specific transaction while the customer is looking at their phone.
 */
class TerminalController extends Controller
{
    /**
     * Check the current status of a pending NiaLink transaction.
     * The POS system calls this repeatedly until it's 'completed' or 'failed'.
     */
    public function checkStatus($reference)
    {
        // 1. Find the transaction by its public reference
        $transaction = Transaction::where('reference', $reference)
            ->firstOrFail();

        // 2. Return the status so the till can react
        return response()->json([
            'status' => strtoupper($transaction->status),
            'reference' => $transaction->reference,
            'amount' => number_format((float) $transaction->amount, 2),
            'is_finalized' => in_array($transaction->status, ['completed', 'failed', 'expired']),
        ]);
    }
}
