<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ConsumerUserSeeder extends Seeder
{
    /**
     * Create test consumer accounts with wallets, devices, and balances.
     *
     * Consumers authenticate via phone + PIN + device_id on the mobile app.
     * For Postman testing, use the login endpoint:
     *
     *   POST /api/consumer/auth/login
     *   {
     *     "phone_number": "0712345678",
     *     "pin": "1234",
     *     "device_id": "test-device-consumer-1"
     *   }
     *
     * Consumer accounts:
     *   Consumer 1 (Wanjiru) — KES 50,000 balance, tier_2 KYC
     *   Consumer 2 (Kamau)   — KES 10,000 balance, tier_1 KYC
     *   Consumer 3 (Aisha)   — KES 0 balance, tier_1 KYC (for top-up testing)
     */
    public function run(WalletService $walletService): void
    {
        $consumers = [
            [
                'name'         => 'Wanjiru Kamau',
                'phone_number' => '0712345678',
                'pin'          => '1234',
                'kyc_level'    => 'tier_2',
                'device_id'    => 'test-device-consumer-1',
                'balance'      => 50000.00,
            ],
            [
                'name'         => 'John Kamau',
                'phone_number' => '0722987654',
                'pin'          => '1234',
                'kyc_level'    => 'tier_1',
                'device_id'    => 'test-device-consumer-2',
                'balance'      => 10000.00,
            ],
            [
                'name'         => 'Aisha Hassan',
                'phone_number' => '0733456789',
                'pin'          => '1234',
                'kyc_level'    => 'tier_1',
                'device_id'    => 'test-device-consumer-3',
                'balance'      => 0.00,
            ],
        ];

        foreach ($consumers as $data) {
            $user = User::firstOrCreate(
                ['phone_number' => $data['phone_number']],
                [
                    'name'         => $data['name'],
                    'phone_number' => $data['phone_number'],
                    'pin'          => $data['pin'], // hashed by model cast
                    'primary_type' => 'consumer',
                    'kyc_level'    => $data['kyc_level'],
                    'status'       => 'active', // already verified — skip OTP flow
                    'currency'     => 'KES',
                ]
            );

            $user->syncRoles(['consumer']);

            // Create wallet if it doesn't exist
            $wallet = $walletService->createFor($user);

            // Seed a starting balance directly for testing
            // In production wallets are only funded via M-Pesa top-up
            if ($data['balance'] > 0 && (float) $wallet->balance === 0.0) {
                $wallet->update([
                    'balance'        => $data['balance'],
                    'total_credited' => $data['balance'],
                ]);
            }

            // Register and trust a test device
            // This means login will succeed without going through OTP flow
            UserDevice::updateOrCreate(
                ['user_id' => $user->id, 'device_id' => $data['device_id']],
                [
                    'device_name' => 'Postman Test Device',
                    'platform'    => 'android',
                    'status'      => 'active',
                    'is_trusted'  => true,
                    'trusted_at'  => now(),
                ]
            );

            $this->command->info(
                "✅ Consumer: {$data['name']} | Phone: {$data['phone_number']} | " .
                "Balance: KES " . number_format($data['balance'], 2)
            );
        }
    }
}
