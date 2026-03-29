<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class Transaction extends Model
{
    use HasFactory, HasUppercaseUuid;

    protected $fillable = [
        'user_id',
        'sender_id',
        'receiver_id',
        'merchant_id',
        'terminal_id',
        'reference',
        'payment_code_reference',
        'amount',
        'fee',
        'currency',
        'type',
        'status',
        'failure_reason',
        'description',
        'metadata',
    ];

    // nialink_code is gone — the 6-digit code lives in Redis only,
    // never in the database. No hidden fields needed here.

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'fee'        => 'decimal:2',
            'metadata'   => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // ===========
    // BOOT
    // ===========

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Transaction $transaction) {
            // UUID is handled by HasUppercaseUuid trait.

            // Auto-generate reference if not provided.
            // Format: NL-YYYYMMDD-XXXXXXXX
            if (empty($transaction->reference)) {
                $transaction->reference = 'NL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
            }

            // Default currency to KES
            if (empty($transaction->currency)) {
                $transaction->currency = 'KES';
            }
        });
    }

    // ===============
    // RELATIONSHIPS
    // ===============

    /**
     * The user who initiated this transaction (maps to user_id).
     * For p2m: the paying consumer.
     * For p2p: the sender.
     * For topup/withdrawal: the account holder.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Explicit P2P sender (maps to sender_id).
     * Null for p2m, topup, and withdrawal transactions.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * P2P recipient (maps to receiver_id).
     * Null for p2m, topup, and withdrawal transactions.
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * The merchant receiving payment (p2m only).
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * The POS terminal that submitted the payment claim.
     * Enables per-terminal reporting and fraud detection.
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * The atomic debit and credit ledger entries for this transaction.
     * A completed p2m transaction always has exactly two entries.
     * A failed transaction has zero entries.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The M-Pesa transaction linked to this record.
     * Only populated for topup and withdrawal transactions.
     */
    public function mpesaTransaction(): HasOne
    {
        return $this->hasOne(MpesaTransaction::class);
    }

    /**
     * AML flags raised against this specific transaction.
     */
    public function amlFlags(): HasMany
    {
        return $this->hasMany(AmlFlag::class);
    }

    // ==========
    // HELPERS
    // ==========

    /**
     * Has the 120-second payment window passed?
     * Applies to both pending and processing states —
     * a processing transaction that was never settled is also expired.
     */
    public function isExpired(): bool
    {
        return in_array($this->status, ['pending', 'processing'])
            && $this->created_at->addSeconds(120)->isPast();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Net amount received by the merchant after NiaLink fee.
     * For p2m: amount - fee
     * For all other types: amount (no fee)
     */
    public function netAmount(): float
    {
        return (float) $this->amount - (float) $this->fee;
    }

    /**
     * Formatted reference for receipts and support.
     * Always uppercase — consistent with what the user sees on screen.
     */
    public function formattedReference(): string
    {
        return strtoupper($this->reference);
    }
}
