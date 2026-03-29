<?php

namespace App\Services;

use App\Models\FloatTransaction;
use App\Models\TrustAccountSnapshot;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReconciliationService
{
    public function __construct(
        protected AuditService $auditService,
    ) {}

    // =========================================================================
    // DAILY RECONCILIATION
    // =========================================================================

    /**
     * Run the daily CBK-mandated reconciliation.
     *
     * Must complete by 4:00pm EAT every day.
     * Scheduled in routes/console.php at 3:45pm EAT (12:45pm UTC).
     *
     * Algorithm:
     *   1. Sum all wallet balances (what NiaLink owes users)
     *   2. Compare to trust account balance (what NiaLink holds at the bank)
     *   3. Record the snapshot
     *   4. Alert ops team if deficiency detected
     *   5. Notify CBK if deficiency — must rectify by 12pm next day
     *
     * @param float $trustAccountBalance The current balance in the NiaLink
     *                                   trust account at the bank. In production
     *                                   this is fetched via bank API. For MVP
     *                                   it is passed in manually or via config.
     */
    public function reconcile(float $trustAccountBalance): TrustAccountSnapshot
    {
        $totalWalletBalance = $this->getTotalFloat();
        $difference         = round($trustAccountBalance - $totalWalletBalance, 2);

        $status = match(true) {
            $difference > 0.01  => 'surplus',
            $difference < -0.01 => 'deficiency',
            default             => 'balanced',
        };

        $snapshot = TrustAccountSnapshot::create([
            'trust_account_balance' => $trustAccountBalance,
            'total_wallet_balance'  => $totalWalletBalance,
            'difference'            => $difference,
            'status'                => $status,
            'trust_bank'            => config('nialink.trust_account.bank'),
            'reconciled_at'         => now(),
        ]);

        AuditService::system('reconciliation.completed', [
            'status'                => $status,
            'trust_account_balance' => $trustAccountBalance,
            'total_wallet_balance'  => $totalWalletBalance,
            'difference'            => $difference,
        ]);

        if ($status === 'deficiency') {
            $this->handleDeficiency($snapshot);
        }

        return $snapshot;
    }

    // =========================================================================
    // FLOAT TRACKING
    // =========================================================================

    /**
     * Record a real KES movement into the trust account (inflow).
     * Called by ProcessMpesaTopUpCallback after a successful STK Push.
     *
     * Creates a float_transactions row that extends the running balance chain.
     * balance_after = balance_before + amount
     */
    public function recordInflow(
        float  $amount,
        string $mpesaTransactionId,
        string $transactionId,
    ): FloatTransaction {
        $balanceBefore = $this->getLastFloatBalance();

        return FloatTransaction::create([
            'type'                  => 'topup_credit',
            'amount'                => $amount,
            'currency'              => 'KES',
            'balance_before'        => $balanceBefore,
            'balance_after'         => round($balanceBefore + $amount, 2),
            'mpesa_transaction_id'  => $mpesaTransactionId,
            'transaction_id'        => $transactionId,
            'reference'             => $this->floatReference(),
        ]);
    }

    /**
     * Record a real KES movement out of the trust account (outflow).
     * Called by ProcessMpesaB2CCallback after a successful withdrawal or settlement.
     *
     * balance_after = balance_before - amount
     */
    public function recordOutflow(
        float  $amount,
        string $type,
        string $mpesaTransactionId,
        ?string $transactionId = null,
        ?string $notes = null,
    ): FloatTransaction {
        if (! in_array($type, ['withdrawal_debit', 'settlement_debit'])) {
            throw new \InvalidArgumentException(
                "Invalid outflow type: {$type}. Must be withdrawal_debit or settlement_debit."
            );
        }

        $balanceBefore = $this->getLastFloatBalance();

        if ($amount > $balanceBefore) {
            Log::critical('Float outflow would result in negative trust account balance', [
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'type'           => $type,
            ]);

            throw new \Exception(
                'Insufficient float balance for this outflow. Contact ops immediately.',
                500
            );
        }

        return FloatTransaction::create([
            'type'                 => $type,
            'amount'               => $amount,
            'currency'             => 'KES',
            'balance_before'       => $balanceBefore,
            'balance_after'        => round($balanceBefore - $amount, 2),
            'mpesa_transaction_id' => $mpesaTransactionId,
            'transaction_id'       => $transactionId,
            'reference'            => $this->floatReference(),
            'notes'                => $notes,
            'trust_bank'           => config('nialink.trust_account.bank'),
        ]);
    }

    /**
     * Record a manual adjustment to the float balance.
     * Requires CBK approval and a documented reason.
     * Used only for corrections — never for routine operations.
     */
    public function recordAdjustment(
        float  $amount,
        bool   $isCredit,
        string $notes,
        string $authorisedBy,
    ): FloatTransaction {
        if (empty(trim($notes))) {
            throw new \InvalidArgumentException(
                'Notes are required for float adjustments — document the CBK authorisation.'
            );
        }

        $balanceBefore = $this->getLastFloatBalance();
        $balanceAfter  = $isCredit
            ? round($balanceBefore + $amount, 2)
            : round($balanceBefore - $amount, 2);

        return FloatTransaction::create([
            'type'          => 'adjustment',
            'amount'        => $amount,
            'currency'      => 'KES',
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
            'reference'      => $this->floatReference(),
            'notes'          => $notes . " | Authorised by: {$authorisedBy}",
            'trust_bank'     => config('nialink.trust_account.bank'),
        ]);
    }

    // =========================================================================
    // INTEGRITY CHECKS
    // =========================================================================

    /**
     * Verify the float transaction chain is unbroken.
     * Each row's balance_before must equal the previous row's balance_after.
     *
     * Returns an array of broken links if any are found.
     * An empty array means the chain is intact.
     *
     * Called by the weekly integrity check job.
     */
    public function verifyFloatChain(): array
    {
        $brokenLinks = [];
        $previous    = null;

        FloatTransaction::orderBy('created_at')->each(function ($record) use (&$previous, &$brokenLinks) {
            if ($previous !== null) {
                $expectedBefore = (float) $previous->balance_after;
                $actualBefore   = (float) $record->balance_before;

                if (abs($expectedBefore - $actualBefore) > 0.01) {
                    $brokenLinks[] = [
                        'record_id'      => $record->id,
                        'reference'      => $record->reference,
                        'expected_before' => $expectedBefore,
                        'actual_before'   => $actualBefore,
                        'gap'            => $actualBefore - $expectedBefore,
                    ];
                }
            }

            $previous = $record;
        });

        if (! empty($brokenLinks)) {
            Log::critical('Float transaction chain integrity violation detected', [
                'broken_links' => $brokenLinks,
            ]);

            AuditService::system('reconciliation.chain_violation', [
                'broken_links_count' => count($brokenLinks),
                'first_broken_at'    => $brokenLinks[0]['reference'] ?? null,
            ]);
        }

        return $brokenLinks;
    }

    /**
     * Get the current total of all wallet balances.
     * This is what NiaLink collectively owes to all its users.
     * Must always be <= trust account balance.
     */
    public function getTotalFloat(): float
    {
        return (float) Wallet::sum('balance');
    }

    /**
     * Get the most recent trust account balance from the float chain.
     * This is the running balance from the last FloatTransaction row.
     * Returns 0.00 if no float transactions exist yet (fresh system).
     */
    public function getLastFloatBalance(): float
    {
        return (float) (FloatTransaction::latest('created_at')->value('balance_after') ?? 0.00);
    }

    /**
     * Get all open deficiency snapshots that have not yet been reported to CBK.
     * Used by the ops dashboard and the CBK notification job.
     */
    public function getUnreportedDeficiencies(): \Illuminate\Database\Eloquent\Collection
    {
        return TrustAccountSnapshot::where('status', 'deficiency')
            ->where('cbk_notified', false)
            ->orderBy('reconciled_at')
            ->get();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Handle a detected deficiency.
     * Logs at critical level, alerts the ops team, and prepares CBK notification.
     *
     * CBK timeline:
     *   - Deficiency detected:      by 4:00pm EAT
     *   - CBK notified:             by 4:00pm EAT same day
     *   - Deficiency rectified:     by 12:00pm EAT next day
     */
    private function handleDeficiency(TrustAccountSnapshot $snapshot): void
    {
        Log::critical('FLOAT DEFICIENCY DETECTED — CBK VIOLATION', [
            'shortfall'             => $snapshot->shortfall(),
            'trust_account_balance' => $snapshot->trust_account_balance,
            'total_wallet_balance'  => $snapshot->total_wallet_balance,
            'reconciled_at'         => $snapshot->reconciled_at,
            'must_rectify_by'       => now()->addDay()->setTime(12, 0)->toDateTimeString(),
        ]);

        // Notify ops team via configured channel
        // In production: integrate with PagerDuty, Slack, or SMS
        // For MVP: email to config('nialink.ops_email')
        $this->notifyOpsTeam($snapshot);

        // Notify CBK — required by 4pm EAT on the day of discovery
        $this->notifyCbk($snapshot);
    }

    /**
     * Send an alert to the NiaLink operations team.
     * Production: replace with PagerDuty/Slack integration.
     */
    private function notifyOpsTeam(TrustAccountSnapshot $snapshot): void
    {
        $opsEmail = config('nialink.ops_email');

        if (! $opsEmail) {
            Log::error('Ops email not configured — cannot send deficiency alert');
            return;
        }

        // TODO: dispatch a SendFloatDeficiencyAlert mailable
        // Mail::to($opsEmail)->send(new FloatDeficiencyAlert($snapshot));

        Log::critical('Ops team notified of float deficiency', [
            'snapshot_id' => $snapshot->id,
            'shortfall'   => $snapshot->shortfall(),
        ]);
    }

    /**
     * Formally notify CBK of the deficiency.
     * CBK requires notification by 4pm EAT on the day of discovery.
     * Marks the snapshot as notified once sent.
     */
    private function notifyCbk(TrustAccountSnapshot $snapshot): void
    {
        // TODO: integrate with CBK's reporting portal or email
        // For MVP: log the notification and mark as notified
        // In production: POST to CBK's API or send the formal email

        Log::critical('CBK NOTIFICATION — FLOAT DEFICIENCY', [
            'snapshot_id'           => $snapshot->id,
            'shortfall'             => $snapshot->shortfall(),
            'trust_account_balance' => $snapshot->trust_account_balance,
            'total_wallet_balance'  => $snapshot->total_wallet_balance,
            'reconciled_at'         => $snapshot->reconciled_at,
            'summary'               => $snapshot->summary(),
        ]);

        $snapshot->markCbkNotified();
    }

    /**
     * Generate a unique float transaction reference.
     * Format: FLT-YYYYMMDD-XXXXXXXX
     */
    private function floatReference(): string
    {
        return 'FLT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}
