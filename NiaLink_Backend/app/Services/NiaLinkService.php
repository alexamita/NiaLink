<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Http;
use Exception;

class NiaLinkService
{
    /**
     * Phase 1: Code Generation
     */
    public function generateCode($userId)
    {
        do {
            $code = (string) rand(100000, 999999);
        } while (Cache::has("nialink_auth_{$code}"));

        return DB::transaction(function () use ($userId, $code) {
            $transaction = Transaction::create([
                'user_id' => $userId,
                'reference' => 'NL-' . strtoupper(bin2hex(random_bytes(4))),
                'nialink_code' => $code,
                'amount' => 0,
                'type' => 'p2m',
                'status' => 'pending',
            ]);

            Cache::put("nialink_auth_{$code}", $transaction->id, 120);
            $this->logActivity($userId, 'code_generated', ['code' => $code]);

            return ['code' => $code, 'expires_in' => 120];
        });
    }

    /**
     * Phase 2: Merchant Claim (The Handshake)
     * Validates the code and puts the transaction into 'processing' for PIN approval.
     */
    public function completePayment($code, $merchantCode, $amount)
    {
        $lock = Cache::lock("processing_nialink_{$code}", 10);

        return $lock->get(function () use ($code, $merchantCode, $amount) {
            $merchant = Merchant::where('merchant_code', $merchantCode)->first();
            $transactionId = Cache::get("nialink_auth_{$code}");
            $transaction = Transaction::where('id', $transactionId)->first();

            try {
                if (!$merchant) throw new Exception("merchant_not_found");
                if (!$transaction || $transaction->status !== 'pending') {
                    $this->logActivity(null, 'invalid_code_attempt', ['code' => $code, 'merchant' => $merchantCode]);
                    throw new Exception("invalid_or_expired_code");
                }

                // Update transaction to 'processing' and attach the merchant/amount
                // This triggers the Push Notification on the User's device.
                $transaction->update([
                    'merchant_id' => $merchant->id,
                    'amount' => $amount,
                    'status' => 'processing'
                ]);

                // We do NOT settle money here yet. We wait for Phase 3 (PIN).
                return ['status' => 'success', 'message' => 'Waiting for user approval', 'transaction_id' => $transaction->id];

            } catch (Exception $e) {
                return ['status' => 'error', 'message' => str_replace('_', ' ', $e->getMessage())];
            }
        }) ?: ['status' => 'error', 'message' => 'Concurrent processing detected.'];
    }

    /**
     * Phase 3: User Confirmation (PIN Approval)
     */
    public function confirmPayment($transactionId, $pin)
    {
        $result = DB::transaction(function () use ($transactionId, $pin) {
            $transaction = Transaction::where('id', $transactionId)
                ->where('status', 'processing')
                ->lockForUpdate()
                ->first();

            if (!$transaction) throw new Exception("Transaction not found or already processed.");

            $user = $transaction->user;

            // 1. PIN Validation
            if (!Hash::check($pin, $user->pin_hash)) {
                $this->logActivity($user->id, 'invalid_pin_attempt', ['tx_id' => $transactionId]);
                throw new Exception("Invalid PIN.");
            }

            // 2. Execute Settlement
            return $this->executeSettlement($transaction);
        });

        // 3. Post-Settlement Webhook (Must be outside the transaction block)
        if ($result['status'] === 'success') {
            $this->dispatchMerchantWebhook(Transaction::find($transactionId));
        }

        return $result;
    }

    /**
     * Internal Logic: Atomic Money Movement
     */
    private function executeSettlement(Transaction $transaction)
    {
        $merchant = $transaction->merchant;

        // Pessimistic locking ensures no other process can change these balances
        $userWallet = Wallet::where('walletable_id', $transaction->user_id)
            ->where('walletable_type', 'App\Models\User')
            ->lockForUpdate()->first();

        $merchantWallet = Wallet::where('walletable_id', $merchant->id)
            ->where('walletable_type', 'App\Models\Merchant')
            ->lockForUpdate()->first();

        $amount = (float) $transaction->amount;

        if ($userWallet->balance < $amount) {
            $transaction->update(['status' => 'failed']);
            throw new Exception("insufficient_funds");
        }

        $fee = $amount * 0.01;
        $netAmount = $amount - $fee;

        $userWallet->decrement('balance', $amount);
        $merchantWallet->increment('balance', $netAmount);

        $this->createLedgerPair($transaction, $userWallet, $merchantWallet, $amount, $netAmount);

        $transaction->update(['fee' => $fee, 'status' => 'completed']);
        Cache::forget("nialink_auth_{$transaction->nialink_code}");

        $this->logActivity($transaction->user_id, 'payment_completed', ['amount' => $transaction->amount, 'merchant' => $merchant->business_name]);

        return ['status' => 'success', 'message' => 'Payment successful'];
    }

    private function dispatchMerchantWebhook(Transaction $transaction)
    {
        $merchant = $transaction->merchant;
        if ($merchant && $merchant->webhook_url) {
            Http::async()->post($merchant->webhook_url, [
                'event' => 'payment.completed',
                'data' => [
                    'reference' => $transaction->reference,
                    'amount' => $transaction->amount,
                    'status' => 'completed',
                ],
                'signature' => hash_hmac('sha256', $transaction->reference, $merchant->api_key)
            ]);
        }
    }

    private function createLedgerPair($transaction, $userWallet, $merchantWallet, $amount, $netAmount)
    {
        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id' => $userWallet->id,
            'amount' => -$amount,
            'post_balance' => $userWallet->balance,
            'entry_type' => 'debit'
        ]);

        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id' => $merchantWallet->id,
            'amount' => $netAmount,
            'post_balance' => $merchantWallet->balance,
            'entry_type' => 'credit'
        ]);
    }

    private function logActivity($userId, $action, $metadata)
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
        ]);
    }
}
