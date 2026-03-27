<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Represents a customer, their identity, and their transaction constraints.
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'pin',
        'device_id',
        'fcm_token',
        'user_role',
        'daily_limit_p2m',
        'daily_limit_p2p',
        'daily_transaction_count_limit',
        'biometric_enabled',
        'status',
        'kyc_level',
        'currency',
        'last_login_at',
    ];

    protected $hidden = [
        'pin',
        'password',
        'remember_token',
    ];

    /**
     * Cast attributes to specific types for financial and security consistency.
     */
    protected function casts(): array
    {
        return [
            'pin'              => 'hashed',
            'password'         => 'hashed',
            'biometric_enabled' => 'boolean',
            'last_login_at'    => 'datetime',
            'daily_limit_p2m'  => 'decimal:2',
            'daily_limit_p2p'  => 'decimal:2',
        ];
    }

    /* ----------------*/
    /* RELATIONSHIPS  */
    /* ---------------*/

    /**
     * Link to the polymorphic wallet.
     */
    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'walletable');
    }

    /**
     * Track all payment intents and history.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Security and activity trail.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Merchants owned or managed by this user.
     */
    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }
}
