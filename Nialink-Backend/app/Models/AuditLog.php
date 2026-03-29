<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUppercaseUuid;

class AuditLog extends Model
{
    use HasFactory, HasUppercaseUuid;

    // The audit_logs table has no updated_at column.
    // These records are immutable — created once, never modified.
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ===================================================
    // BOOT — Enforce immutability and retention policies
    // ===================================================
    protected static function boot(): void
    {
        parent::boot();
        // UUID is handled by HasUppercaseUuid trait.

        // Audit logs must never be modified after creation.
        // If this fires, something in the codebase is wrong.
        static::updating(function () {
            throw new \LogicException(
                'AuditLog records are immutable and cannot be updated.'
            );
        });

        // Audit logs must never be deleted — CBK requires 5-year retention,
        // 7 years for AML-related records.
        static::deleting(function () {
            throw new \LogicException(
                'AuditLog records cannot be deleted. ' .
                'CBK requires a minimum 5-year retention period.'
            );
        });
    }

    // =================
    // RELATIONSHIPS
    // =================

    /**
     * The user who performed the action.
     * Null for system/scheduled events or if the user account was deleted.
     * nullOnDelete on the FK means the log survives user deletion.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==========
    // HELPERS
    // ==========

    /**
     * Resolve the actual model instance affected by this log entry.
     * Used in admin dashboard to show context alongside the log event.
     *
     * Returns null if:
     *   - resource_type or resource_id is not set (system events)
     *   - The referenced model has been soft-deleted
     *   - The class does not exist (stale log from a removed feature)
     */
    public function resource(): ?Model
    {
        if (! $this->resource_type || ! $this->resource_id) {
            return null;
        }

        if (! class_exists($this->resource_type)) {
            return null;
        }

        return $this->resource_type::find($this->resource_id);
    }

    /**
     * Convenience factory — create a log entry from anywhere in one call.
     * Automatically captures the current request's IP and user agent.
     *
     * Usage:
     *   AuditLog::record('merchant.approved', $merchant, auth()->id());
     *   AuditLog::record('payment_code.generated', null, $user->id, ['expires_in' => 120]);
     */
    public static function record(
        string          $action,
        ?Model          $resource = null,
        int|string|null $userId   = null,
        array           $metadata = [],
    ): self {
        return static::create([
            'user_id'       => $userId ?? auth()->id(),
            'action'        => $action,
            'resource_type' => $resource ? get_class($resource) : null,
            'resource_id'   => $resource?->getKey(),
            'metadata'      => $metadata ?: null,
            'ip_address'    => request()?->ip(),
            'user_agent'    => request()?->userAgent(),
        ]);
    }
}
