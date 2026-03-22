<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Captures non-financial behavioral events for security auditing and fraud detection.
 */
class AuditLog extends Model
{
    use HasFactory;

    // Audit logs should only be created, never updated.
    protected $fillable = [
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    /**
     * Cast the metadata JSON to an array for easy manipulation in PHP.
     */
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /* --------------- */
    /* RELATIONSHIPS   */
    /* --------------  */

    /**
     * The user (Customer or Admin) who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Optional: Get the actual model instance affected by this log.
     * (e.g., The Merchant that was blocked).
     */
    public function resource()
    {
        if (!$this->resource_type || !$this->resource_id) {
            return null;
        }

        return $this->resource_type::find($this->resource_id);
    }
}
