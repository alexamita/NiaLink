<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Traits\HasUppercaseUuid;

class TrustAccountSnapshot extends Model
{
    use HasFactory, HasUppercaseUuid;

    // The trust_account_snapshots table has no updated_at column.
    // Financial figures are immutable once written.
    // Only cbk_notified and cbk_notified_at may be updated post-creation.
    const UPDATED_AT = null;

    protected $fillable = [
        'trust_account_balance',
        'total_wallet_balance',
        'difference',
        'status',
        'cbk_notified',
        'cbk_notified_at',
        'notes',
        'trust_bank',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'trust_account_balance' => 'decimal:2',
            'total_wallet_balance'  => 'decimal:2',
            'difference'            => 'decimal:2',
            'cbk_notified'          => 'boolean',
            'cbk_notified_at'       => 'datetime',
            'reconciled_at'         => 'datetime',
            'created_at'            => 'datetime',
        ];
    }

    // ======
    // BOOT
    // ======

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TrustAccountSnapshot $snapshot) {
            // UUID is handled by HasUppercaseUuid trait.

            // Default reconciled_at to now if not explicitly provided.
            // ReconciliationService always sets this explicitly — this is
            // a safety fallback for manual or test records.
            if (empty($snapshot->reconciled_at)) {
                $snapshot->reconciled_at = Carbon::now();
            }
        });

        // Block updates to financial figures — they are immutable once written.
        // cbk_notified and cbk_notified_at are the only legitimate post-creation
        // updates, handled explicitly by ReconciliationService::notifyCbk().
        // Rather than blocking all updates, we guard against financial field changes.
        static::updating(function (TrustAccountSnapshot $snapshot) {
            $financialFields = [
                'trust_account_balance',
                'total_wallet_balance',
                'difference',
                'status',
                'reconciled_at',
            ];

            foreach ($financialFields as $field) {
                if ($snapshot->isDirty($field)) {
                    throw new \LogicException(
                        "TrustAccountSnapshot field '{$field}' is immutable " .
                        "and cannot be changed after creation."
                    );
                }
            }
        });
    }

    // =========
    // HELPERS
    // =========

    public function isBalanced(): bool
    {
        return $this->status === 'balanced';
    }

    public function isSurplus(): bool
    {
        return $this->status === 'surplus';
    }

    /**
     * A deficiency means NiaLink owes users more than it holds in trust.
     * This is a CBK violation that must be rectified by 12pm the next day
     * and reported to CBK by 4pm on the day of discovery.
     */
    public function isDeficiency(): bool
    {
        return $this->status === 'deficiency';
    }

    /**
     * Has this deficiency been formally reported to CBK?
     * Only meaningful when isDeficiency() is true.
     */
    public function hasBeenReportedToCbk(): bool
    {
        return $this->cbk_notified && $this->cbk_notified_at !== null;
    }

    /**
     * Mark this snapshot as reported to CBK.
     * Called by ReconciliationService after sending the CBK notification.
     */
    public function markCbkNotified(): void
    {
        $this->update([
            'cbk_notified'    => true,
            'cbk_notified_at' => now(),
        ]);
    }

    /**
     * The absolute shortfall in KES when a deficiency exists.
     * Returns 0.00 for balanced and surplus snapshots.
     * Used in CBK notification emails and ops alerts.
     */
    public function shortfall(): float
    {
        return $this->isDeficiency() ? abs((float) $this->difference) : 0.00;
    }

    /**
     * Human-readable summary for ops alerts and CBK reports.
     * Example: "DEFICIENCY: NiaLink trust account is short by KES 15,000.00
     *           Trust: KES 985,000.00 | Wallets: KES 1,000,000.00"
     */
    public function summary(): string
    {
        return sprintf(
            "%s: Trust: KES %s | Wallets: KES %s | Difference: KES %s",
            strtoupper($this->status),
            number_format((float) $this->trust_account_balance, 2),
            number_format((float) $this->total_wallet_balance, 2),
            number_format((float) $this->difference, 2),
        );
    }
}
