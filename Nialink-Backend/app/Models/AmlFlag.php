<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class AmlFlag extends Model
{
    use HasFactory, HasUppercaseUuid;

    // The aml_flags table has no updated_at column.
    // Creation timestamp is immutable — only status, review fields,
    // and frc_reference are updated post-creation.
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'flag_type',
        'severity',
        'status',
        'notes',
        'review_notes',
        'frc_reference',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    // =========
    //   BOOT
    // =========
    protected static function boot(): void
    {
        parent::boot();

        // UUID is handled by HasUppercaseUuid trait.

        // Protect immutable fields — flag_type, severity, user_id,
        // transaction_id, and notes must never change after creation.
        // Only status, review_notes, frc_reference, reviewed_by,
        // and reviewed_at are legitimate post-creation updates.
        static::updating(function (AmlFlag $flag) {
            $immutableFields = [
                'user_id',
                'transaction_id',
                'flag_type',
                'severity',
                'notes',
            ];

            foreach ($immutableFields as $field) {
                if ($flag->isDirty($field)) {
                    throw new \LogicException(
                        "AmlFlag field '{$field}' is immutable and cannot " .
                        "be changed after creation. The original flag must " .
                        "stand as the factual record of what was detected."
                    );
                }
            }
        });

        static::deleting(function () {
            throw new \LogicException(
                'AmlFlag records cannot be deleted. ' .
                'POCAMLA requires AML records to be retained for 7 years.'
            );
        });
    }

    // ===============
    //  RELATIONSHIPS
    // ===============

    /**
     * The user whose activity triggered this flag.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The specific transaction that triggered this flag.
     * Null for account-level flags (pep_match, sanctions_match)
     * that are not tied to a single transaction.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The admin who reviewed and resolved this flag.
     * Null while status = open or under_review.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ==========================
    //  HELPERS — Status checks
    // ==========================
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'under_review';
    }

    public function isCleared(): bool
    {
        return $this->status === 'cleared';
    }

    public function isReported(): bool
    {
        return $this->status === 'reported';
    }

    public function isResolved(): bool
    {
        return in_array($this->status, ['cleared', 'reported']);
    }

    // ============================
    //  HELPERS — Severity checks
    // ============================
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * Does this flag block transactions until resolved?
     * high_value and unusual_pattern are flagged but allowed.
     * structuring, velocity, pep_match, sanctions_match block all transactions.
     */
    public function isBlocking(): bool
    {
        return in_array($this->flag_type, [
            'structuring',
            'velocity',
            'pep_match',
            'sanctions_match',
        ]);
    }

    // =============================
    //  HELPERS — State transitions
    // =============================

    /**
     * Compliance team claims this flag for review.
     * Transitions: open → under_review
     */
    public function claimForReview(User $reviewer): void
    {
        if (! $this->isOpen()) {
            throw new \LogicException(
                "Only open flags can be claimed for review. Current status: {$this->status}"
            );
        }

        $this->update([
            'status'      => 'under_review',
            'reviewed_by' => $reviewer->id,
        ]);
    }

    /**
     * Compliance team clears the flag — no suspicious activity confirmed.
     * Transitions: under_review → cleared
     * review_notes are required before clearing.
     */
    public function clear(User $reviewer, string $reviewNotes): void
    {
        if (! $this->isUnderReview()) {
            throw new \LogicException(
                "Only flags under review can be cleared. Current status: {$this->status}"
            );
        }

        if (empty(trim($reviewNotes))) {
            throw new \InvalidArgumentException(
                'Review notes are required before clearing an AML flag.'
            );
        }

        $this->update([
            'status'       => 'cleared',
            'review_notes' => $reviewNotes,
            'reviewed_by'  => $reviewer->id,
            'reviewed_at'  => now(),
        ]);
    }

    /**
     * Compliance team files an STR with FRC Kenya.
     * Transitions: under_review → reported
     * Both review_notes and frc_reference are required.
     */
    public function reportToFrc(User $reviewer, string $frcReference, string $reviewNotes): void
    {
        if (! $this->isUnderReview()) {
            throw new \LogicException(
                "Only flags under review can be reported. Current status: {$this->status}"
            );
        }

        if (empty(trim($frcReference))) {
            throw new \InvalidArgumentException(
                'FRC Kenya reference number is required when reporting an STR.'
            );
        }

        if (empty(trim($reviewNotes))) {
            throw new \InvalidArgumentException(
                'Review notes are required when filing an STR with FRC Kenya.'
            );
        }

        $this->update([
            'status'        => 'reported',
            'frc_reference' => $frcReference,
            'review_notes'  => $reviewNotes,
            'reviewed_by'   => $reviewer->id,
            'reviewed_at'   => now(),
        ]);
    }
}
