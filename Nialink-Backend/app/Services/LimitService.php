<?php

namespace App\Services;

use App\Models\User;

class LimitService
{
    /**
     * The absolute floor for any limit type.
     * No override or KYC tier can go below these values.
     * Prevents a misconfigured override from setting a limit to zero.
     */
    private const FLOOR = [
        'p2m'   => 10,
        'p2p'   => 10,
        'atm'   => 10,
        'count' => 1,
    ];

    /**
     * Resolve the effective limit for a user and transaction type.
     *
     * Resolution order:
     *   1. Active non-expired user_limit_overrides row
     *   2. KYC tier default from config/kyc_limits.php
     *   3. System floor (FLOOR constant above)
     *
     * Usage:
     *   $limitService->resolve($user, 'p2m')   → 50000.0  (tier_1 default)
     *   $limitService->resolve($user, 'p2p')   → 500000.0 (admin override)
     *   $limitService->resolve($user, 'count') → 20.0     (tier_1 default)
     */
    public function resolve(User $user, string $type): float
    {
        // Step 1: check for an active admin override
        $override = $user->limitOverride;

        if ($override !== null && $override->isActive()) {
            $overrideValue = $override->getLimit($type);

            if ($overrideValue !== null) {
                return max((float) $overrideValue, (float) (self::FLOOR[$type] ?? 0));
            }
        }

        // Step 2: KYC tier default from config/kyc_limits.php
        $tierValue = config("kyc_limits.{$user->kyc_level}.{$type}", 0);

        // Step 3: never go below the system floor
        return max((float) $tierValue, (float) (self::FLOOR[$type] ?? 0));
    }

    /**
     * Check whether a given amount is within the user's effective limit.
     *
     * Usage:
     *   if (! $limitService->withinLimit($user, 'p2m', 5000)) {
     *       throw new Exception('Exceeds daily P2M limit');
     *   }
     */
    public function withinLimit(User $user, string $type, float $amount): bool
    {
        return $amount <= $this->resolve($user, $type);
    }

    /**
     * How much of the daily limit has the user already used today?
     * Used to show remaining balance in the app and enforce daily totals.
     *
     * Note: this counts completed transactions only — pending and failed
     * transactions do not consume limit headroom.
     */
    public function usedToday(User $user, string $type): float
    {
        if ($type === 'count') {
            return (float) $user->transactions()
                ->where('status', 'completed')
                ->whereDate('created_at', today())
                ->count();
        }

        $column = match ($type) {
            'p2m'  => ['type' => 'p2m',        'column' => 'amount'],
            'p2p'  => ['type' => 'p2p',         'column' => 'amount'],
            'atm'  => ['type' => 'withdrawal',  'column' => 'amount'],
            default => null,
        };

        if (! $column) {
            return 0.0;
        }

        return (float) $user->transactions()
            ->where('type', $column['type'])
            ->where('status', 'completed')
            ->whereDate('created_at', today())
            ->sum($column['column']);
    }

    /**
     * How much of the daily limit does the user still have available?
     * Used for display in the app — "You can still send up to KES X today."
     */
    public function remainingToday(User $user, string $type): float
    {
        return max(0.0, $this->resolve($user, $type) - $this->usedToday($user, $type));
    }

    /**
     * Check all three conditions for a transaction to proceed:
     *   1. Amount is within the per-transaction limit
     *   2. Daily total would not be exceeded
     *   3. Daily transaction count would not be exceeded
     *
     * Returns an array with 'allowed' bool and 'reason' string if blocked.
     */
    public function check(User $user, string $type, float $amount): array
    {
        // Per-transaction limit
        if (! $this->withinLimit($user, $type, $amount)) {
            return [
                'allowed' => false,
                'reason'  => "Amount exceeds your single transaction limit of KES " .
                    number_format($this->resolve($user, $type), 2),
            ];
        }

        // Daily cumulative limit
        $used      = $this->usedToday($user, $type);
        $limit     = $this->resolve($user, $type);
        $remaining = $limit - $used;

        if ($amount > $remaining) {
            return [
                'allowed' => false,
                'reason'  => "Amount exceeds your remaining daily limit of KES " .
                    number_format($remaining, 2),
            ];
        }

        // Daily transaction count limit
        $countUsed  = $this->usedToday($user, 'count');
        $countLimit = $this->resolve($user, 'count');

        if ($countUsed >= $countLimit) {
            return [
                'allowed' => false,
                'reason'  => "You have reached your daily transaction limit of " .
                    (int) $countLimit . " transactions.",
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
