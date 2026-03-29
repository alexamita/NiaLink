<?php

/*
|--------------------------------------------------------------------------
| KYC Tier Transaction Limits
|--------------------------------------------------------------------------
|
| These are the DEFAULT limits per KYC tier. They apply when no
| user_limit_overrides row exists for a given user, or when the
| override has expired.
|
| Limit resolution order in LimitService:
|   1. Active non-expired user_limit_overrides row
|   2. These tier defaults
|   3. System floor (hardcoded in LimitService — cannot go below this)
|
| Amounts are in KES.
| CBK regulations as of 2025:
|   - Tier 1 (phone verified):  max wallet KES 300,000
|   - Tier 2 (ID verified):     max wallet KES 500,000
|   - Tier 3 (full KYC):        max wallet KES 1,000,000
|
*/

return [

    'tier_1' => [
        // Person-to-Merchant: Nia-Code POS and online checkout
        'p2m'   => 50_000,
        // Person-to-Person: direct transfers
        'p2p'   => 20_000,
        // ATM / cash withdrawal
        'atm'   => 10_000,
        // Max number of transactions per day
        'count' => 20,
    ],

    'tier_2' => [
        'p2m'   => 150_000,
        'p2p'   => 70_000,
        'atm'   => 40_000,
        'count' => 50,
    ],

    'tier_3' => [
        'p2m'   => 1_000_000,
        'p2p'   => 300_000,
        'atm'   => 100_000,
        'count' => 200,
    ],

];
