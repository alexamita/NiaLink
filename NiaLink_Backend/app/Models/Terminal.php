<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a specific Point of Sale (POS) unit or digital till.
 */
class Terminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'terminal_code',
        'terminal_secret',
        'status',
        'location_note',
        'last_active_at',
    ];

    protected $hidden = [
        'terminal_secret', // Sensitive credential used for request signing
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
    ];

    /* -------------- */
    /* RELATIONSHIPS  */
    /* -------------- */

    /**
     * The parent merchant business this terminal belongs to.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * All transactions initiated or processed specifically by this terminal.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /* --------- */
    /* HELPERS   */
    /* --------- */

    /**
     * Check if the terminal is permitted to process transactions.
     */
    public function isOperational(): bool
    {
        return $this->status === 'active' && $this->merchant->status === 'active';
    }
}
