<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class Wallet extends Model
{
    use HasFactory, HasUppercaseUuid;

    protected $fillable = [
        'walletable_id',
        'walletable_type',
        'balance',
        'total_credited',
        'total_debited',
        'currency',
        'status',
        'freeze_reason',
        'frozen_at',
        'unfrozen_at',
        'last_transaction_at',
    ];

    protected function casts(): array
    {
        return [
            'balance'             => 'decimal:2',
            'total_credited'      => 'decimal:2',
            'total_debited'       => 'decimal:2',
            'frozen_at'           => 'datetime',
            'unfrozen_at'         => 'datetime',
            'last_transaction_at' => 'datetime',
        ];
    }

    // ==============
    // RELATIONSHIPS
    // ==============

    /**
     * The owner of this wallet — either a User or a Merchant.
     * Resolved via the walletable_type and walletable_id columns.
     */
    public function walletable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * All ledger entries that have affected this wallet's balance.
     * Every debit and credit produces one entry here.
     * The full transaction history can be reconstructed from this relationship.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    // =========
    // HELPERS
    // =========

    /**
     * Check if the wallet can be debited for a given amount.
     * Both conditions must be true:
     *   1. Wallet is active (not frozen)
     *   2. Balance is sufficient
     *
     * Use this before initiating any debit — WalletService also checks
     * both conditions internally, but calling this first gives a clean
     * early-exit with a meaningful error message.
     */
    public function canDebit(float $amount): bool
    {
        return $this->isAvailable() && (float) $this->balance >= $amount;
    }

    /**
     * Check if the wallet can receive a credit.
     * Frozen wallets cannot receive funds either.
     */
    public function canCredit(): bool
    {
        return $this->isAvailable();
    }

    /**
     * Check if the wallet has enough funds regardless of frozen status.
     * Use canDebit() in transaction flows — this is for display only
     * (e.g. showing whether a user "could" afford something).
     */
    public function hasSufficientFunds(float $amount): bool
    {
        return (float) $this->balance >= $amount;
    }

    /**
     * Check if the wallet is active and not frozen.
     * The single source of truth for operational status.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    /**
     * Verify the wallet balance matches the sum of its ledger entries.
     * Used by ReconciliationService for integrity checks.
     * Returns true if balanced, false if discrepancy detected.
     */
    public function isBalanceIntact(): bool
    {
        $ledgerSum = $this->ledgerEntries()->sum('amount');
        return abs((float) $this->balance - (float) $ledgerSum) < 0.01;
    }

    /**
     * Formatted balance for display — always shows two decimal places.
     * Usage: $wallet->formattedBalance() → "KES 1,250.00"
     */
    public function formattedBalance(): string
    {
        return $this->currency . ' ' . number_format((float) $this->balance, 2);
    }
}
