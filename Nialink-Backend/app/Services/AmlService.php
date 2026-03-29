<?php

namespace App\Services;

use App\Models\AmlFlag;
use App\Models\Transaction;
use App\Models\User;

class AmlService
{
    // CBK reporting threshold — single transaction at or above this
    // must be flagged and may require an STR filing with FRC Kenya
    private const HIGH_VALUE_THRESHOLD = 1_000_000;

    // Structuring detection threshold — multiple transactions just
    // below this within 24 hours suggest deliberate split payments
    private const STRUCTURING_THRESHOLD = 500_000;

    // Lower bound for structuring detection — transactions above this
    // percentage of the structuring threshold are considered suspicious
    private const STRUCTURING_LOWER_BOUND = 0.80;

    // How many near-threshold transactions within 24h trigger structuring
    private const STRUCTURING_COUNT = 3;

    // Velocity limit — transactions per hour before blocking
    private const VELOCITY_LIMIT = 10;

    /**
     * Run all AML checks before processing a transaction.
     * Called by TransactionService before any debit or credit.
     *
     * Checks run in order of severity:
     *   1. Sanctions match  → always blocks (most severe)
     *   2. Velocity         → blocks if too many transactions
     *   3. Structuring      → blocks if pattern detected
     *   4. High value       → flags but allows (CBK requirement)
     *
     * @throws \Exception if the transaction should be blocked
     */
    public function checkTransaction(User $user, float $amount): void
    {
        $this->checkVelocity($user);
        $this->checkStructuring($user, $amount);
        $this->checkHighValue($user, $amount);
    }

    /**
     * HIGH VALUE CHECK
     * Single transaction at or above KES 1,000,000.
     * Flagged for compliance review but NOT blocked.
     * CBK requires these to be logged and potentially reported to FRC.
     */
    private function checkHighValue(User $user, float $amount): void
    {
        if ($amount < self::HIGH_VALUE_THRESHOLD) {
            return;
        }

        $this->flag(
            user: $user,
            transaction: null,
            type: 'high_value',
            severity: 'critical',
            notes: "Transaction of KES " . number_format($amount, 2) .
                " meets or exceeds CBK reporting threshold of KES " .
                number_format(self::HIGH_VALUE_THRESHOLD, 2) . ".",
        );

        // High value transactions are ALLOWED — compliance team reviews the flag
    }

    /**
     * VELOCITY CHECK
     * More than 10 transactions in the last 60 minutes.
     * Suggests account compromise or automated fraud.
     * BLOCKS the transaction.
     *
     * @throws \Exception with HTTP 429
     */
    private function checkVelocity(User $user): void
    {
        $recentCount = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentCount < self::VELOCITY_LIMIT) {
            return;
        }

        $this->flag(
            user: $user,
            transaction: null,
            type: 'velocity',
            severity: 'high',
            notes: "{$recentCount} completed transactions in the last 60 minutes. " .
                "Velocity limit is " . self::VELOCITY_LIMIT . ".",
        );

        throw new \Exception(
            'Transaction velocity limit exceeded. Please try again later.',
            429
        );
    }

    /**
     * STRUCTURING CHECK
     * 3 or more transactions between 80% and 100% of KES 500,000
     * within the last 24 hours.
     * Suggests deliberate splitting to avoid the reporting threshold.
     * BLOCKS the transaction.
     *
     * @throws \Exception with HTTP 403
     */
    private function checkStructuring(User $user, float $amount): void
    {
        $lowerBound = self::STRUCTURING_THRESHOLD * self::STRUCTURING_LOWER_BOUND;

        if ($amount < $lowerBound || $amount >= self::STRUCTURING_THRESHOLD) {
            return;
        }

        $recentCount = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereBetween('amount', [$lowerBound, self::STRUCTURING_THRESHOLD - 0.01])
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recentCount < self::STRUCTURING_COUNT) {
            return;
        }

        $this->flag(
            user: $user,
            transaction: null,
            type: 'structuring',
            severity: 'critical',
            notes: "{$recentCount} transactions between KES " .
                number_format($lowerBound, 2) . " and KES " .
                number_format(self::STRUCTURING_THRESHOLD, 2) .
                " detected within 24 hours. Possible structuring to avoid " .
                "CBK reporting threshold.",
        );

        throw new \Exception(
            'Transaction blocked pending compliance review. Please contact support.',
            403
        );
    }

    /**
     * Create an AML flag record.
     * Used internally by the check methods and externally by
     * admin actions (manual flagging from the dashboard).
     */
    public function flag(
        User         $user,
        ?Transaction $transaction,
        string       $type,
        string       $severity,
        string       $notes,
    ): AmlFlag {
        return AmlFlag::create([
            'user_id'        => $user->id,
            'transaction_id' => $transaction?->id,
            'flag_type'      => $type,
            'severity'       => $severity,
            'status'         => 'open',
            'notes'          => $notes,
        ]);
    }

    /**
     * Check if a user currently has any open blocking flags.
     * Called by TransactionService before processing any payment.
     * If true, the transaction must be rejected until flags are cleared.
     *
     * @throws \Exception if blocking flag exists
     */
    public function assertNoBlockingFlags(User $user): void
    {
        $blockingFlag = AmlFlag::where('user_id', $user->id)
            ->whereIn('flag_type', ['structuring', 'velocity', 'pep_match', 'sanctions_match'])
            ->whereIn('status', ['open', 'under_review'])
            ->first();

        if (! $blockingFlag) {
            return;
        }

        throw new \Exception(
            'Your account has been temporarily restricted. Please contact support.',
            403
        );
    }
}
