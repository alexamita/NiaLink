<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tracks the lifecycle of payment intents from code generation to final settlement.
 */
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',     // Unique public ID (e.g., NL-X89J2)
        'user_id',
        'merchant_id',
        'terminal_id',   // Identifies the specific till used
        'amount',
        'fee',           // NiaLink's commission
        'currency',
        'type',          // p2m, p2p, withdrawal
        'status',        // pending, completed, failed, expired
        'description',
    ];

    protected $hidden = [
    'nialink_code', // Hidden from all standard JSON serialization
];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /* -------------- */
    /* RELATIONSHIPS  */
    /* -------------- */

    /**
     * The customer who initiated the payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The business receiving the funds.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * The specific point of sale (till) where the code was entered.
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * The atomic debit and credit entries created by this transaction.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /* --------- */
    /* HELPERS  */
    /* -------- */

    /**
     * Checks if the transaction code is still within its validity window.
     */
    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->created_at->addMinutes(2)->isPast();
    }
}
