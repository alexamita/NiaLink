<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class UserDevice extends Model
{
    use HasFactory, HasUppercaseUuid;

    protected $fillable = [
        'user_id',
        'device_id',
        'fcm_token',
        'device_name',
        'platform',
        'app_version',
        'status',
        'is_trusted',
        'trusted_at',
        'deactivated_at',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'is_trusted'     => 'boolean',
            'trusted_at'     => 'datetime',
            'deactivated_at' => 'datetime',
            'last_active_at' => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    // ==============
    // RELATIONSHIPS
    // ==============

    /**
     * The user this device belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // =========================================================================
    // SCOPES
    // Reusable query filters.
    // Usage: UserDevice::active()->first()
    //        $user->devices()->pending()->get()
    // =========================================================================

    /**
     * Active trusted devices — can initiate transactions.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('is_trusted', true);
    }

    /**
     * Pending devices — registered but OTP not yet confirmed.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Superseded devices — displaced by a new login or new device.
     * Used by DeviceService to query the device history for a user.
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->where('status', 'superseded');
    }

    /**
     * Revoked devices — manually blocked by user or admin.
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', 'revoked');
    }

    // ==========
    // HELPERS
    // ==========

    /**
     * Is this device currently active and trusted?
     * The only state from which transactions can be initiated.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->is_trusted;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /**
     * Mark this device as superseded.
     * Called by DeviceService when a new device takes over for this user
     * or when the same device is registered by a different user.
     * Tokens are revoked separately by DeviceService — not here.
     */
    public function supersede(): void
    {
        $this->update([
            'status'         => 'superseded',
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Mark this device as revoked.
     * Called on explicit logout or admin block.
     * Tokens are revoked separately by DeviceService — not here.
     */
    public function revoke(): void
    {
        $this->update([
            'status'         => 'revoked',
            'is_trusted'     => false,
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Mark this device as fully trusted after OTP confirmation.
     * Called by DeviceService::trustDevice() after successful verification.
     */
    public function trust(): void
    {
        $this->update([
            'status'     => 'active',
            'is_trusted' => true,
            'trusted_at' => now(),
        ]);
    }

    /**
     * Update the last active timestamp.
     * Called by the active.device middleware on every authenticated request.
     */
    public function touchActivity(): void
    {
        $this->update(['last_active_at' => now()]);
    }
}
