<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    /**
     * Debit a wallet — money leaving.
     *
     * MUST be called inside DB::transaction().
     * Uses lockForUpdate() to prevent race conditions when two requests
     * try to debit the same wallet simultaneously.
     *
     * What this does in order:
     *   1. Re-fetches the wallet with a row-level lock
     *   2. Checks the wallet is active and has sufficient funds
     *   3. Decrements balance and increments total_debited
     *   4. Writes an immutable LedgerEntry for the audit trail
     *
     * @throws \Exception if wallet frozen, insufficient funds
     */
    public function debit(
        Wallet      $wallet,
        float       $amount,
        Transaction $transaction,
        string      $description = '',
    ): void {
        // Re-fetch with lock — ensures no other process changed the balance
        // between the caller reading it and this write happening
        $wallet = Wallet::lockForUpdate()->findOrFail($wallet->id);

        if (! $wallet->canDebit($amount)) {
            if ($wallet->isFrozen()) {
                throw new \Exception('Wallet is frozen and cannot be debited.', 403);
            }
            throw new \Exception(
                'Insufficient balance. Available: KES ' .
                    number_format((float) $wallet->balance, 2),
                422
            );
        }

        $wallet->decrement('balance', $amount);
        $wallet->increment('total_debited', $amount);
        $wallet->update(['last_transaction_at' => now()]);

        $postBalance = (float) $wallet->balance - $amount;

        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id'      => $wallet->id,
            'amount'         => -$amount, // negative = money leaving
            'post_balance'   => $postBalance,
            'entry_type'     => 'debit',
            'description'    => $description,
        ]);
    }

    /**
     * Credit a wallet — money entering.
     *
     * MUST be called inside DB::transaction().
     * Uses lockForUpdate() — same pattern as debit().
     *
     * @throws \Exception if wallet frozen
     */
    public function credit(
        Wallet      $wallet,
        float       $amount,
        Transaction $transaction,
        string      $description = '',
    ): void {
        $wallet = Wallet::lockForUpdate()->findOrFail($wallet->id);

        if (! $wallet->canCredit()) {
            throw new \Exception('Wallet is frozen and cannot receive funds.', 403);
        }

        $wallet->increment('balance', $amount);
        $wallet->increment('total_credited', $amount);
        $wallet->update(['last_transaction_at' => now()]);

        $postBalance = (float) $wallet->balance - $amount;

        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id'      => $wallet->id,
            'amount'         => $amount, // positive = money entering
            'post_balance'   => $postBalance,
            'entry_type'     => 'credit',
            'description'    => $description,
        ]);
    }

    /**
     * Create a wallet for any walletable model (User or Merchant).
     *
     * Uses firstOrCreate — calling this twice is safe and idempotent.
     * Called by:
     *   - UserAuthService::registerConsumer() on registration
     *   - ManagementController::approveMerchant() on KYC approval
     */
    public function createFor(Model $walletable, string $currency = 'KES'): Wallet
    {
        return $walletable->wallet()->firstOrCreate(
            [
                'walletable_type' => get_class($walletable),
                'walletable_id'   => $walletable->id,
            ],
            [
                'balance'         => 0.00,
                'total_credited'  => 0.00,
                'total_debited'   => 0.00,
                'currency'        => $currency,
                'status'          => 'active',
            ]
        );
    }

    /**
     * Freeze a wallet — blocks all inbound and outbound transactions.
     * Used during fraud investigations or CBK-mandated holds.
     * Requires a reason for the CBK audit trail.
     */
    public function freeze(Wallet $wallet, string $reason): void
    {
        if ($wallet->isFrozen()) {
            return; // Already frozen — idempotent
        }

        $wallet->update([
            'status'       => 'frozen',
            'freeze_reason' => $reason,
            'frozen_at'    => now(),
            'unfrozen_at'  => null,
        ]);
    }

    /**
     * Unfreeze a wallet — restores normal operation.
     * Should only be called after AML flag is cleared or CBK approval received.
     */
    public function unfreeze(Wallet $wallet): void
    {
        if (! $wallet->isFrozen()) {
            return; // Already active — idempotent
        }

        $wallet->update([
            'status'       => 'active',
            'freeze_reason' => null,
            'unfrozen_at'  => now(),
        ]);
    }

    /**
     * Transfer between two wallets atomically.
     * Convenience wrapper around debit + credit — always use this
     * for internal transfers to ensure both sides happen or neither does.
     *
     * @throws \Exception if either wallet operation fails
     */
    public function transfer(
        Wallet      $from,
        Wallet      $to,
        float       $amount,
        Transaction $transaction,
        string      $debitDescription  = '',
        string      $creditDescription = '',
    ): void {
        // Both operations are inside the same DB::transaction() call
        // from the caller — if either throws, both roll back
        $this->debit($from, $amount, $transaction, $debitDescription);
        $this->credit($to, $amount, $transaction, $creditDescription);
    }

    /**
     * Verify a wallet's balance matches its ledger entry sum.
     * Used by ReconciliationService for per-wallet integrity checks.
     *
     * Returns true if balanced (within KES 0.01 rounding tolerance).
     * Returns false if a discrepancy is detected — triggers an alert.
     */
    public function verifyIntegrity(Wallet $wallet): bool
    {
        $ledgerSum = $wallet->ledgerEntries()->sum('amount');
        return abs((float) $wallet->balance - (float) $ledgerSum) < 0.01;
    }

    /**
     * Get the current balance of the NiaLink system as a whole.
     * sum(all wallet balances) — must equal the trust account balance.
     * Called by ReconciliationService at 3:45pm EAT daily.
     */
    public function systemFloat(): float
    {
        return (float) Wallet::where('status', 'active')->sum('balance')
            + (float) Wallet::where('status', 'frozen')->sum('balance');
    }
}
