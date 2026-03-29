<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantSeeder extends Seeder
{
    /**
     * Create test merchant accounts with wallets.
     *
     * Merchant admins log in via the admin dashboard (email + password):
     *
     *   POST /api/admin/auth/login
     *   { "email": "merchant@javakenya.co.ke", "password": "Merchant@2026!" }
     *
     * Merchants created:
     *   1. Java House Kenya  — active, verified, KES 15,000 in wallet
     *   2. Naivas Supermarket — active, verified, KES 0 wallet
     *   3. New Merchant Co   — pending KYC (for approve flow testing)
     */
    public function run(WalletService $walletService): void
    {
        $merchants = [
            [
                'owner' => [
                    'name'     => 'Java House Admin',
                    'email'    => 'merchant@javakenya.co.ke',
                    'password' => 'Merchant@2026!',
                ],
                'merchant' => [
                    'business_name'              => 'Java House Kenya',
                    'category'                   => 'restaurant',
                    'kra_pin'                    => 'A001234567B',
                    'business_license_no'        => 'BUS-KE-001',
                    'status'                     => 'active',
                    'verified_at'                => now(),
                    'settlement_bank_name'       => 'Equity Bank',
                    'settlement_bank_account_no' => '0010234567890',
                    'webhook_url'                => 'https://webhook.site/nialink-java',
                ],
                'wallet_balance' => 15000.00,
            ],
            [
                'owner' => [
                    'name'     => 'Naivas Admin',
                    'email'    => 'merchant@naivas.co.ke',
                    'password' => 'Merchant@2026!',
                ],
                'merchant' => [
                    'business_name'              => 'Naivas Supermarket',
                    'category'                   => 'retail',
                    'kra_pin'                    => 'B009876543A',
                    'business_license_no'        => 'BUS-KE-002',
                    'status'                     => 'active',
                    'verified_at'                => now(),
                    'settlement_bank_name'       => 'KCB Bank',
                    'settlement_bank_account_no' => '1234567890',
                ],
                'wallet_balance' => 0.00,
            ],
            [
                'owner' => [
                    'name'     => 'Pending Merchant Admin',
                    'email'    => 'merchant@pending.co.ke',
                    'password' => 'Merchant@2026!',
                ],
                'merchant' => [
                    'business_name'  => 'New Merchant Co',
                    'category'       => 'services',
                    'kra_pin'        => 'C112233445D',
                    'status'         => 'pending', // for testing approve flow
                ],
                'wallet_balance' => 0.00,
            ],
        ];

        foreach ($merchants as $data) {
            // Create the merchant owner user
            $owner = User::firstOrCreate(
                ['email' => $data['owner']['email']],
                [
                    'name'         => $data['owner']['name'],
                    'email'        => $data['owner']['email'],
                    'password'     => Hash::make($data['owner']['password']),
                    'primary_type' => 'merchant_admin',
                    'kyc_level'    => 'tier_3',
                    'status'       => 'active',
                    'currency'     => 'KES',
                ]
            );

            $owner->syncRoles(['merchant_admin']);

            // Create the merchant record
            $merchant = Merchant::updateOrCreate(
                ['kra_pin' => $data['merchant']['kra_pin']],
                array_merge($data['merchant'], ['user_id' => $owner->id])
            );

            // Create and fund the wallet for active merchants
            $wallet = $walletService->createFor($merchant);

            if ($data['wallet_balance'] > 0 && (float) $wallet->balance === 0.0) {
                $wallet->update([
                    'balance'        => $data['wallet_balance'],
                    'total_credited' => $data['wallet_balance'],
                ]);
            }

            $statusIcon = $data['merchant']['status'] === 'active' ? '✅' : '⏳';
            $this->command->info(
                "{$statusIcon} Merchant: {$data['merchant']['business_name']} | " .
                "Code: {$merchant->merchant_code} | " .
                "Status: {$data['merchant']['status']}"
            );
        }
    }
}
