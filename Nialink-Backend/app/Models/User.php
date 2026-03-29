<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\HasUppercaseUuid;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes, HasUppercaseUuid;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'pin',
        'password',
        'primary_type',
        'kyc_level',
        'status',
        'biometric_enabled',
        'currency',
        'last_login_at',
    ];

    protected $hidden = [
        'pin',
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            // 'hashed' cast automatically bcrypts on save —
            // no need to call Hash::make() manually in controllers.
            'pin'               => 'hashed',
            'password'          => 'hashed',
            'biometric_enabled' => 'boolean',
            'last_login_at'     => 'datetime',
            'deleted_at'        => 'datetime',
        ];
    }

    // ==============
    // RELATIONSHIPS
    // ==============
    /**
     * All registered devices for this user — includes full history
     * (pending, active, superseded, revoked).
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * The single currently active trusted device.
     * Used by DeviceService and the active.device middleware.
     */
    public function activeDevice(): HasOne
    {
        return $this->hasOne(UserDevice::class)
            ->where('status', 'active')
            ->where('is_trusted', true);
    }

    /**
     * Admin-set limit override for this user.
     * NULL columns fall back to KYC tier defaults in config/kyc_limits.php.
     */
    public function limitOverride(): HasOne
    {
        return $this->hasOne(UserLimitOverride::class);
    }

    /**
     * Polymorphic personal wallet.
     * Created automatically on registration by UserAuthService.
     */
    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'walletable');
    }

    /**
     * All transactions initiated by this user (user_id column).
     * Used by ManagementController and the admin transaction ledger.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Transactions where this user is the explicit P2P sender.
     * Uses sender_id FK — distinct from the general user_id.
     */
    public function sentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_id');
    }

    /**
     * Transactions where this user is the P2P receiver.
     */
    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'receiver_id');
    }

    /**
     * M-Pesa top-up and withdrawal records for this user.
     * Used by WalletController to show top-up history.
     */
    public function mpesaTransactions(): HasMany
    {
        return $this->hasMany(MpesaTransaction::class);
    }

    /**
     * Security and compliance audit trail.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * AML flags raised against this user's activity.
     */
    public function amlFlags(): HasMany
    {
        return $this->hasMany(AmlFlag::class);
    }

    /**
     * Merchant accounts owned by this user.
     * A user can own multiple merchants (different businesses).
     */
    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    // =========
    // HELPERS
    // =========

    /**
     * Resolve the effective transaction limit for a given type.
     *
     * Resolution order:
     *   1. Active non-expired user_limit_overrides row
     *   2. KYC tier default from config/kyc_limits.php
     *
     * Usage: $user->getLimit('p2m')   → float (KES)
     *        $user->getLimit('p2p')   → float (KES)
     *        $user->getLimit('atm')   → float (KES)
     *        $user->getLimit('count') → int (transactions/day)
     */
    public function getLimit(string $type): float
    {
        $override = $this->limitOverride;
        $column   = "daily_limit_{$type}";

        if ($override?->$column !== null) {
            if (! $override->expires_at || $override->expires_at->isFuture()) {
                return (float) $override->$column;
            }
        }

        return (float) config("kyc_limits.{$this->kyc_level}.{$type}", 0);
    }

    /**
     * Check if this user's account is fully operational.
     * Pending verification and suspended users cannot transact.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isConsumer(): bool
    {
        return $this->primary_type === 'consumer';
    }

    public function isMerchantAdmin(): bool
    {
        return $this->primary_type === 'merchant_admin';
    }

    public function isStaff(): bool
    {
        return $this->primary_type === 'staff';
    }
}
