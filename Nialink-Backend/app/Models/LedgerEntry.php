<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class LedgerEntry extends Model
{
    use HasFactory, HasUppercaseUuid;

    // The ledger_entries table has no updated_at column.
    // These records are immutable — created once, never modified.
    // Setting this to null tells Eloquent not to attempt writing it.
    const UPDATED_AT = null;

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'amount',
        'post_balance',
        'entry_type',
        'description',
    ];

    protected function casts(): array
    {
        return [
            // Signed amount — positive = credit, negative = debit.
            // Consistent with the migration comment and WalletService behavior.
            'amount'       => 'decimal:2',
            'post_balance' => 'decimal:2',
            'created_at'   => 'datetime',
        ];
    }

    // =============================
    // BOOT — Enforce immutability
    // =============================

    protected static function boot(): void
    {
        parent::boot();

        // UUID is handled by HasUppercaseUuid trait.

        // Hard block on updates — ledger entries must never be modified.
        // If this fires, something in the codebase is wrong. Fix the caller, not this guard.
        static::updating(function () {
            throw new \LogicException(
                'LedgerEntry records are immutable and cannot be updated. ' .
                'Create a compensating entry instead.'
            );
        });

        // Hard block on deletes — financial history cannot be erased.
        // The DB also enforces this via restrictOnDelete on the FK,
        // but this gives a cleaner error message from the application layer.
        static::deleting(function () {
            throw new \LogicException(
                'LedgerEntry records cannot be deleted. ' .
                'They are part of a permanent financial audit trail.'
            );
        });
    }

    // ================
    // RELATIONSHIPS
    // ================

    /**
     * The transaction that triggered this ledger movement.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The wallet that was debited or credited.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    // ==========
    // HELPERS
    // ==========

    /**
     * Money entered this wallet.
     * Amount column is positive for credits.
     */
    public function isCredit(): bool
    {
        return $this->entry_type === 'credit';
    }

    /**
     * Money left this wallet.
     * Amount column is negative for debits.
     */
    public function isDebit(): bool
    {
        return $this->entry_type === 'debit';
    }

    /**
     * The absolute value of the movement regardless of direction.
     * Useful for display — avoids showing negative numbers to users.
     */
    public function absoluteAmount(): float
    {
        return abs((float) $this->amount);
    }
}
