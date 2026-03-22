<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The polymorphic container for all liquid funds within the ecosystem.
 */
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'walletable_id',
        'walletable_type',
        'balance',
        'currency',
        'status',
        'last_transaction_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'last_transaction_at' => 'datetime',
    ];

    /* --------------- */
    /* RELATIONSHIPS   */
    /* --------------- */

    /**
     * The owner of the wallet (User or Merchant).
     */
    public function walletable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * All ledger entries (debits/credits) that have affected this balance.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /* ---------*/
    /* HELPERS  */
    /* ---------*/

    /**
     * Check if the wallet has enough funds for a transaction.
     */
    public function hasSufficientFunds(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Check if the wallet is frozen or restricted by Admin.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }
}
