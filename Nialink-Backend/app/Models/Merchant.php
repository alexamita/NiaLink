<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class Merchant extends Model
{
    use HasFactory, SoftDeletes, HasUppercaseUuid;

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
        'rejection_reason',
        'settlement_bank_name',
        'settlement_bank_account_no',
        'mpesa_paybill',
        'mpesa_till_number',
    ];

    protected $hidden = [
        // Never expose in API responses — used only for HMAC webhook signing
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'deleted_at'  => 'datetime',
        ];
    }

    // ===========================================================
    // BOOT — Auto-generate merchant_code and api_key on creation
    // ===========================================================

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Merchant $merchant) {
            // UUID is handled by HasUppercaseUuid trait.

            // Internal reference — never typed at POS
            // Format: NL-XXXXXX (6 random uppercase chars)
            if (empty($merchant->merchant_code)) {
                $merchant->merchant_code = 'NL-' . strtoupper(Str::random(6));
            }

            // Webhook signing secret — generated once, hidden in responses
            if (empty($merchant->api_key)) {
                $merchant->api_key = Str::random(64);
            }
        });
    }

    // ===============
    // RELATIONSHIPS
    // ===============
    /**
     * The platform user who owns and administers this merchant account.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Polymorphic wallet for collected merchant funds.
     * Created by WalletService::createFor() when merchant is approved.
     */
    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'walletable');
    }

    /**
     * All POS terminals registered under this merchant.
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * Only terminals that are currently active.
     * Used at payment validation time.
     */
    public function activeTerminals(): HasMany
    {
        return $this->hasMany(Terminal::class)
            ->where('status', 'active');
    }

    /**
     * All transactions received by this merchant.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * AML flags raised against this merchant's activity.
     */
    public function amlFlags(): HasMany
    {
        return $this->hasMany(AmlFlag::class, 'user_id', 'user_id');
    }

    // ========
    // HELPERS
    // ========

    /**
     * Is this merchant approved and able to accept payments?
     * Both the merchant status AND the terminal status must be active
     * for a payment to go through. This checks only the merchant side.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Has this merchant completed KYC verification?
     * Verified merchants get full transaction limits.
     * Unverified merchants are capped at tier_1 limits.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->status === 'active';
    }

    /**
     * Total revenue earned by this merchant (net of NiaLink fees).
     * Convenience method for the merchant dashboard.
     */
    public function totalRevenue(): float
    {
        return (float) $this->transactions()
            ->where('status', 'completed')
            ->sum('amount') -
            $this->transactions()
            ->where('status', 'completed')
            ->sum('fee');
    }
}
