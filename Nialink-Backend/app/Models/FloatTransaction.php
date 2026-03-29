<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class FloatTransaction extends Model
{
    use HasFactory, HasUppercaseUuid;

    // The float_transactions table has no updated_at column.
    // These records are immutable — the running balance chain
    // must never be broken by post-creation edits.
    const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'amount',
        'currency',
        'balance_before',
        'balance_after',
        'mpesa_transaction_id',
        'transaction_id',
        'reference',
        'notes',
        'trust_bank',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after'  => 'decimal:2',
            'created_at'     => 'datetime',
        ];
    }

    // ======
    //  BOOT
    // ======
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (FloatTransaction $float) {

            // UUID is handled by HasUppercaseUuid trait.

            // Auto-generate reference if not provided.
            // Format: FLT-YYYYMMDD-XXXXXXXX
            if (empty($float->reference)) {
                $float->reference = 'FLT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
            }

            if (empty($float->currency)) {
                $float->currency = 'KES';
            }

            // Validate the running balance chain before saving.
            // balance_after must equal balance_before ± amount depending on type.
            // This catches any FloatService calculation errors at write time.
            $expected = in_array($float->type, ['topup_credit'])
                ? (float) $float->balance_before + (float) $float->amount
                : (float) $float->balance_before - (float) $float->amount;

            if (abs($expected - (float) $float->balance_after) > 0.01) {
                throw new \LogicException(
                    "FloatTransaction balance chain is broken. " .
                    "Expected balance_after: {$expected}, " .
                    "got: {$float->balance_after}. " .
                    "Check FloatService calculation."
                );
            }
        });

        // The running balance chain must never be broken by post-creation edits.
        static::updating(function () {
            throw new \LogicException(
                'FloatTransaction records are immutable and cannot be updated. ' .
                'Create an adjustment entry instead.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'FloatTransaction records cannot be deleted. ' .
                'They form an unbroken chain of the NiaLink trust account history.'
            );
        });
    }

    // ==============
    // RELATIONSHIPS
    // ==============
    /**
     * The M-Pesa transaction that caused this float movement.
     * Populated for topup_credit and withdrawal_debit types.
     * Null for settlement_debit (batch) and adjustment types.
     *
     * Note: mpesa_transaction_id is a raw UUID column, not a foreignUuid,
     * so we specify the owning key explicitly.
     */
    public function mpesaTransaction(): BelongsTo
    {
        return $this->belongsTo(MpesaTransaction::class, 'mpesa_transaction_id');
    }

    /**
     * The internal transaction this float movement corresponds to.
     * Populated for topup and withdrawal types.
     * Null for settlement_debit (batch) and adjustment types.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // =========
    // HELPERS
    // =========

    public function isInflow(): bool
    {
        return $this->type === 'topup_credit';
    }

    public function isOutflow(): bool
    {
        return in_array($this->type, ['withdrawal_debit', 'settlement_debit']);
    }

    public function isAdjustment(): bool
    {
        return $this->type === 'adjustment';
    }

    /**
     * Verify this record's position in the running balance chain.
     * balance_after must equal balance_before ± amount.
     * Returns true if intact, false if the chain is broken.
     *
     * Used by ReconciliationService to verify float history integrity.
     */
    public function isChainIntact(): bool
    {
        $expected = $this->isInflow()
            ? (float) $this->balance_before + (float) $this->amount
            : (float) $this->balance_before - (float) $this->amount;

        return abs($expected - (float) $this->balance_after) < 0.01;
    }

    /**
     * Human-readable description of this float movement.
     * Used in reconciliation reports and CBK audit exports.
     *
     * Examples:
     *   "TOP-UP: +KES 500.00 (Trust: KES 50,500.00)"
     *   "WITHDRAWAL: -KES 1,000.00 (Trust: KES 49,500.00)"
     *   "SETTLEMENT: -KES 15,000.00 (Trust: KES 34,500.00)"
     */
    public function summary(): string
    {
        $direction = $this->isInflow() ? '+' : '-';
        $type      = strtoupper(str_replace('_', ' ', $this->type));

        return sprintf(
            "%s: %sKES %s (Trust: KES %s)",
            $type,
            $direction,
            number_format((float) $this->amount, 2),
            number_format((float) $this->balance_after, 2),
        );
    }
}
