<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class UserLimitOverride extends Model
{
    use HasFactory, HasUppercaseUuid;

    protected $fillable = [
        'user_id',
        'daily_limit_p2m',
        'daily_limit_p2p',
        'daily_limit_atm',
        'daily_transaction_count_limit',
        'reason',
        'set_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'daily_limit_p2m'               => 'decimal:2',
            'daily_limit_p2p'               => 'decimal:2',
            'daily_limit_atm'               => 'decimal:2',
            'daily_transaction_count_limit' => 'integer',
            'expires_at'                    => 'datetime',
            'created_at'                    => 'datetime',
            'updated_at'                    => 'datetime',
        ];
    }

    // ==============
    // RELATIONSHIPS
    // ==============

    /**
     * The user this override applies to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin who set this override.
     * Nullable — if the admin account is deleted, the override survives.
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    // =========
    // HELPERS
    // =========

    /**
     * Is this override currently in effect?
     * True if no expiry date OR expiry date is in the future.
     */
    public function isActive(): bool
    {
        return ! $this->expires_at || $this->expires_at->isFuture();
    }

    /**
     * Has this override passed its expiry date?
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Is this a permanent override (no expiry date set)?
     */
    public function isPermanent(): bool
    {
        return $this->expires_at === null;
    }

    /**
     * Get the override value for a specific limit type.
     * Returns null if this override does not cover that type
     * (meaning the KYC tier default should be used instead).
     *
     * Usage: $override->getLimit('p2m') → float|null
     *        $override->getLimit('count') → int|null
     */
    public function getLimit(string $type): float|int|null
    {
        if (! $this->isActive()) {
            return null;
        }

        return match($type) {
            'p2m'   => $this->daily_limit_p2m   !== null ? (float) $this->daily_limit_p2m   : null,
            'p2p'   => $this->daily_limit_p2p   !== null ? (float) $this->daily_limit_p2p   : null,
            'atm'   => $this->daily_limit_atm   !== null ? (float) $this->daily_limit_atm   : null,
            'count' => $this->daily_transaction_count_limit ?? null,
            default => null,
        };
    }

    /**
     * Human-readable summary for the admin dashboard.
     * Shows which limits are overridden and their values.
     *
     * Example:
     *   "P2M: KES 500,000 | P2P: KES 200,000 | ATM: default | Count: default"
     */
    public function summary(): string
    {
        $format = fn(?float $v) => $v !== null
            ? 'KES ' . number_format($v, 2)
            : 'default';

        return implode(' | ', [
            'P2M: '   . $format($this->daily_limit_p2m   !== null ? (float) $this->daily_limit_p2m   : null),
            'P2P: '   . $format($this->daily_limit_p2p   !== null ? (float) $this->daily_limit_p2p   : null),
            'ATM: '   . $format($this->daily_limit_atm   !== null ? (float) $this->daily_limit_atm   : null),
            'Count: ' . ($this->daily_transaction_count_limit ?? 'default'),
        ]);
    }
}
