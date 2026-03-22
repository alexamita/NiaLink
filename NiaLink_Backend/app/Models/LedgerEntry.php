<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of an atomic debit or credit to a specific wallet.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    // These records are immutable; only allow creation, no updates.
    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'amount',
        'post_balance',
        'entry_type',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'post_balance' => 'decimal:2',
    ];

    /* --------------- */
    /* RELATIONSHIPS   */
    /* --------------- */

    /**
     * The high-level transaction header that triggered this movement.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The specific wallet being debited or credited.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /* --------- */
    /* HELPERS   */
    /* --------- */

    /**
     * Identifies if this entry represents money entering the wallet.
     */
    public function isCredit(): bool
    {
        return $this->entry_type === 'credit';
    }

    /**
     * Identifies if this entry represents money leaving the wallet.
     */
    public function isDebit(): bool
    {
        return $this->entry_type === 'debit';
    }
}
