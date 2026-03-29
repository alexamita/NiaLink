<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class Terminal extends Model
{
    use HasFactory, HasUppercaseUuid;

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
        // Hashed signing secret — shown once at creation, never retrievable after.
        // Hidden from all API responses and toArray() output.
        'terminal_secret',
    ];

    protected function casts(): array
    {
        return [
            // 'hashed' bcrypts on save automatically.
            // Verify with: Hash::check($plaintext, $terminal->terminal_secret)
            'terminal_secret' => 'hashed',
            'last_active_at'  => 'datetime',
        ];
    }

    // =========================================================================
    // BOOT — Auto-generate terminal_code and terminal_secret on creation
    // =========================================================================

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Terminal $terminal) {
            // UUID is handled by HasUppercaseUuid trait.

            // Public POS identifier — pre-configured on the device during onboarding.
            // Format: TRM-XXXXXXXX (8 random uppercase chars)
            // Submitted on every payment claim to identify the till.
            if (empty($terminal->terminal_code)) {
                $terminal->terminal_code = 'TRM-' . strtoupper(Str::random(8));
            }

            // Signing secret — generated once, shown once in plaintext,
            // then stored hashed. The 'hashed' cast above handles the bcrypt.
            // After creation this value is never readable in plaintext again.
            if (empty($terminal->terminal_secret)) {
                $terminal->terminal_secret = Str::random(64);
            }
        });
    }

    // ==============
    // RELATIONSHIPS
    // ==============

    /**
     * The merchant this terminal belongs to.
     * Always eager-load this when calling isOperational() on multiple terminals.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * All transactions processed by this terminal.
     * Enables per-terminal sales reports and fraud detection.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ========
    // HELPERS
    // ========

    /**
     * Check if this terminal can process payments.
     *
     * A terminal is operational only when BOTH conditions are true:
     *   1. This terminal's own status is 'active'
     *   2. The parent merchant's status is 'active'
     *
     * This means suspending a merchant instantly disables all their
     * terminals without touching any terminal rows.
     *
     * IMPORTANT: always eager-load merchant when calling this on a list:
     *   Terminal::with('merchant')->get()->filter->isOperational()
     *   Never call this in a loop without the merchant already loaded.
     */
    public function isOperational(): bool
    {
        // Use relationLoaded() to avoid triggering a hidden query
        // if merchant was not eager-loaded by the caller.
        if (! $this->relationLoaded('merchant')) {
            $this->load('merchant');
        }

        return $this->status === 'active'
            && $this->merchant !== null
            && $this->merchant->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    /**
     * Mark this terminal as having just processed a payment.
     * Called by TransactionService after every successful p2m payment.
     */
    public function touchActivity(): void
    {
        $this->update(['last_active_at' => now()]);
    }

    /**
     * Generate a fresh plaintext secret for this terminal.
     * Used when a merchant requests a terminal secret rotation.
     * Returns the plaintext secret — shown once, then hashed by the cast.
     */
    public function rotateSecret(): string
    {
        $plaintext = Str::random(64);
        $this->update(['terminal_secret' => $plaintext]);
        return $plaintext;
    }
}
