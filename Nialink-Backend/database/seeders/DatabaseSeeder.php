<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the NiaLink database in strict dependency order.
     *
     * Order matters:
     *   1. Roles & Permissions must exist before users are assigned roles
     *   2. Admin users have no wallet — no WalletService dependency
     *   3. Consumer users need WalletService injected
     *   4. Merchants need users to exist first (owner FK)
     *   5. Terminals need merchants to exist first (merchant_id FK)
     */
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🚀 Seeding NiaLink test data...');
        $this->command->info('');

        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            ConsumerUserSeeder::class,
            MerchantSeeder::class,
            TerminalSeeder::class,
        ]);

        $this->command->info('');
        $this->command->info('✅ All seeders complete. Ready for Postman testing.');
        $this->command->info('');
        $this->printCredentials();
    }

    private function printCredentials(): void
    {
        $this->command->info('════════════════════════════════════════════════');
        $this->command->info('  POSTMAN TEST CREDENTIALS');
        $this->command->info('════════════════════════════════════════════════');
        $this->command->info('');
        $this->command->info('── ADMIN ACCOUNTS (email + password) ──────────');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['super_admin', 'superadmin@nialink.co.ke', 'NiaLink@2026!'],
                ['admin',       'admin@nialink.co.ke',      'NiaLink@2026!'],
            ]
        );
        $this->command->info('Endpoint: POST /api/admin/auth/login');
        $this->command->info('');
        $this->command->info('── MERCHANT ACCOUNTS (email + password) ───────');
        $this->command->table(
            ['Merchant', 'Email', 'Password', 'Status'],
            [
                ['Java House',  'merchant@javakenya.co.ke', 'Merchant@2026!', 'active'],
                ['Naivas',      'merchant@naivas.co.ke',    'Merchant@2026!', 'active'],
                ['New Merchant','merchant@pending.co.ke',   'Merchant@2026!', 'pending'],
            ]
        );
        $this->command->info('Endpoint: POST /api/admin/auth/login');
        $this->command->info('');
        $this->command->info('── CONSUMER ACCOUNTS (phone + PIN + device_id) ─');
        $this->command->table(
            ['Name', 'Phone', 'PIN', 'Device ID', 'Balance'],
            [
                ['Wanjiru Kamau', '0712345678', '1234', 'test-device-consumer-1', 'KES 50,000'],
                ['John Kamau',    '0722987654', '1234', 'test-device-consumer-2', 'KES 10,000'],
                ['Aisha Hassan',  '0733456789', '1234', 'test-device-consumer-3', 'KES 0'],
            ]
        );
        $this->command->info('Endpoint: POST /api/consumer/auth/login');
        $this->command->info('');
        $this->command->info('── POS TERMINALS (terminal_code + terminal_secret) ─');
        $this->command->table(
            ['Terminal', 'Code', 'Secret', 'Status'],
            [
                ['Java House Main',     'TRM-JAVAHOUSE-01',     'test-secret-java-1',   'active'],
                ['Java House Drive-Thru','TRM-JAVAHOUSE-02',    'test-secret-java-2',   'active'],
                ['Naivas Checkout',     'TRM-NAIVAS-01',        'test-secret-naivas-1', 'active'],
                ['Locked Till',         'TRM-JAVAHOUSE-LOCKED', 'test-secret-java-locked','locked'],
            ]
        );
        $this->command->info('Endpoint: POST /api/pos/payment');
        $this->command->info('════════════════════════════════════════════════');
    }
}
