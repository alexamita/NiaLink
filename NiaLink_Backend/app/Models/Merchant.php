<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Represents a business entity and its compliance/integration profile.
 */
class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_name',
        'merchant_code',
        'category',
        'kra_pin',
        'business_license_no',
        'verified_at',
        'api_key',
        'webhook_url',
        'status',
        'bank_name',
        'bank_account_no',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    /* --------------*/
    /* RELATIONSHIPS */
    /* --------------*/

    /**
     * The primary owner/admin of this merchant account.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Polymorphic wallet for business funds.
     */
    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'walletable');
    }

    /**
     * Individual POS units belonging to this merchant.
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * Financial history across all terminals.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
