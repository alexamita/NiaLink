<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Terminal;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * MerchantSeeder
 *
 * Seeds the merchants, terminals, and wallets tables with realistic
 * Kenyan business data for local development and Postman testing.
 *
 * Covers all columns defined in:
 *  - 2026_03_02_182259_create_merchants_table.php
 *  - 2026_03_02_182300_create_terminals_table.php
 *  - 2026_03_02_182310_create_wallets_table.php
 */
class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        // ─────────────────────────────────────────────────────────────
        // Each merchant needs an owner — a User with role 'merchant_admin'.
        // We create dedicated owner accounts here so the seeder is
        // self-contained and does not depend on UserSeeder run order.
        // ─────────────────────────────────────────────────────────────

        $merchantDefinitions = [

            // ── 1. NAIVAS SUPERMARKET ────────────────────────────────
            [
                'owner' => [
                    'name'         => 'Naivas Operations',
                    'phone_number' => '0700100001',
                    'pin'     => Hash::make('1234'),
                    'user_role'    => 'merchant_admin',
                    'status'       => 'active',
                    'kyc_level'    => 'tier_3',
                    'currency'     => 'KES',
                ],
                'merchant' => [
                    'business_name'      => 'Naivas Limited',
                    'merchant_code'      => 'NL-MER-0001',
                    'category'           => 'retail',
                    'kra_pin'            => 'P051234567A',
                    'business_license_no'=> 'BL-2019-NAI-001',
                    'status'             => 'active',
                    'verified_at'        => now()->subMonths(6),
                    'api_key'            => Str::random(64),
                    'webhook_url'        => 'https://api.naivas.co.ke/nialink/webhook',
                    // migration columns:
                    'settlement_bank_name'    => 'Equity Bank Kenya',
                    'settlement_bank_account_no' => '0190234567890',
                ],
                'terminals' => [
                    [
                        'name'          => 'Westlands - Till 01',
                        'terminal_code' => 'NV-WL-T01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Westlands Branch, Ground Floor, Till 1',
                        'last_active_at'=> now()->subMinutes(10),
                    ],
                    [
                        'name'          => 'Westlands - Till 02',
                        'terminal_code' => 'NV-WL-T02',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Westlands Branch, Ground Floor, Till 2',
                        'last_active_at'=> now()->subMinutes(45),
                    ],
                    [
                        'name'          => 'Karen - Till 01',
                        'terminal_code' => 'NV-KR-T01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Karen Branch, Main Checkout',
                        'last_active_at'=> now()->subHours(1),
                    ],
                ],
                'wallet_balance' => 250000.00,
            ],

            // ── 2. JAVA HOUSE ────────────────────────────────────────
            [
                'owner' => [
                    'name'         => 'Java House Finance',
                    'phone_number' => '0700100002',
                    'pin'     => Hash::make('1234'),
                    'user_role'    => 'merchant_admin',
                    'status'       => 'active',
                    'kyc_level'    => 'tier_3',
                    'currency'     => 'KES',
                ],
                'merchant' => [
                    'business_name'      => 'Java House Kenya Ltd',
                    'merchant_code'      => 'NL-MER-0002',
                    'category'           => 'food_and_beverage',
                    'kra_pin'            => 'P051234568B',
                    'business_license_no'=> 'BL-2020-JHK-002',
                    'status'             => 'active',
                    'verified_at'        => now()->subMonths(4),
                    'api_key'            => Str::random(64),
                    'webhook_url'        => 'https://payments.javahousekenya.com/nialink',
                    'settlement_bank_name'    => 'KCB Bank Kenya',
                    'settlement_bank_account_no' => '1234567890123',
                ],
                'terminals' => [
                    [
                        'name'          => 'Village Market - Terminal 01',
                        'terminal_code' => 'JH-VM-T01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Village Market Branch, Counter 1',
                        'last_active_at'=> now()->subMinutes(5),
                    ],
                    [
                        'name'          => 'Sarit Centre - Terminal 01',
                        'terminal_code' => 'JH-SC-T01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Sarit Centre Branch, Counter 1',
                        'last_active_at'=> now()->subHours(2),
                    ],
                ],
                'wallet_balance' => 85000.00,
            ],

            // ── 3. TOTAL ENERGIES (FUEL STATION) ────────────────────
            [
                'owner' => [
                    'name'         => 'TotalEnergies Kenya Ops',
                    'phone_number' => '0700100003',
                    'pin'     => Hash::make('1234'),
                    'user_role'    => 'merchant_admin',
                    'status'       => 'active',
                    'kyc_level'    => 'tier_3',
                    'currency'     => 'KES',
                ],
                'merchant' => [
                    'business_name'      => 'TotalEnergies Marketing Kenya Ltd',
                    'merchant_code'      => 'NL-MER-0003',
                    'category'           => 'fuel',
                    'kra_pin'            => 'P051234569C',
                    'business_license_no'=> 'BL-2021-TEK-003',
                    'status'             => 'active',
                    'verified_at'        => now()->subMonths(3),
                    'api_key'            => Str::random(64),
                    'webhook_url'        => 'https://kenya.totalenergies.com/api/payments',
                    'settlement_bank_name'    => 'Stanbic Bank Kenya',
                    'settlement_bank_account_no' => '9876543210001',
                ],
                'terminals' => [
                    [
                        'name'          => 'Upperhill Station - Pump POS 01',
                        'terminal_code' => 'TE-UH-P01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Upperhill Station, Forecourt Pump 1',
                        'last_active_at'=> now()->subMinutes(20),
                    ],
                    [
                        'name'          => 'Upperhill Station - Pump POS 02',
                        'terminal_code' => 'TE-UH-P02',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Upperhill Station, Forecourt Pump 2',
                        'last_active_at'=> now()->subMinutes(35),
                    ],
                    [
                        'name'          => 'Upperhill Station - Shop Till',
                        'terminal_code' => 'TE-UH-S01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Upperhill Station, Convenience Store',
                        'last_active_at'=> now()->subHours(3),
                    ],
                ],
                'wallet_balance' => 600000.00,
            ],

            // ── 4. QUICKMART ─────────────────────────────────────────
            [
                'owner' => [
                    'name'         => 'Quickmart Payments',
                    'phone_number' => '0700100004',
                    'pin'     => Hash::make('1234'),
                    'user_role'    => 'merchant_admin',
                    'status'       => 'active',
                    'kyc_level'    => 'tier_2',
                    'currency'     => 'KES',
                ],
                'merchant' => [
                    'business_name'      => 'Quickmart Supermarket Ltd',
                    'merchant_code'      => 'NL-MER-0004',
                    'category'           => 'retail',
                    'kra_pin'            => 'P051234570D',
                    'business_license_no'=> 'BL-2022-QMS-004',
                    'status'             => 'active',
                    'verified_at'        => now()->subMonths(2),
                    'api_key'            => Str::random(64),
                    'webhook_url'        => null, // No webhook configured yet
                    'settlement_bank_name'    => 'Co-operative Bank of Kenya',
                    'settlement_bank_account_no' => '0113456789012',
                ],
                'terminals' => [
                    [
                        'name'          => 'Ruaka Branch - Till 01',
                        'terminal_code' => 'QM-RK-T01',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'active',
                        'location_note' => 'Ruaka Branch, Main Floor Till 1',
                        'last_active_at'=> now()->subMinutes(15),
                    ],
                    [
                        'name'          => 'Ruaka Branch - Till 02',
                        'terminal_code' => 'QM-RK-T02',
                        'terminal_secret'=> Hash::make(Str::random(32)),
                        'status'        => 'inactive', // Terminal offline for testing
                        'location_note' => 'Ruaka Branch, Main Floor Till 2 — Currently offline',
                        'last_active_at'=> now()->subDays(2),
                    ],
                ],
                'wallet_balance' => 120000.00,
            ],

            // ── 5. PENDING MERCHANT (Not yet KYC approved) ──────────
            // Useful for testing the admin approval flow in Postman
            [
                'owner' => [
                    'name'         => 'Zuri Boutique Owner',
                    'phone_number' => '0700100005',
                    'pin'     => Hash::make('1234'),
                    'user_role'    => 'merchant_admin',
                    'status'       => 'active',
                    'kyc_level'    => 'tier_1',
                    'currency'     => 'KES',
                ],
                'merchant' => [
                    'business_name'      => 'Zuri Boutique Nairobi',
                    'merchant_code'      => 'NL-MER-0005',
                    'category'           => 'retail',
                    'kra_pin'            => 'P051234571E',
                    'business_license_no'=> 'BL-2026-ZBN-005',
                    'status'             => 'pending',    // ← Awaiting admin KYC approval
                    'verified_at'        => null,         // ← Not yet approved
                    'api_key'            => null,         // ← Issued on approval
                    'webhook_url'        => null,
                    'settlement_bank_name'    => 'NCBA Bank Kenya',
                    'settlement_bank_account_no' => '0234567890123',
                ],
                'terminals' => [], // No terminals until merchant is approved
                'wallet_balance' => 0.00,
            ],

        ];

        // ─────────────────────────────────────────────────────────────
        // CREATE EACH MERCHANT, ITS OWNER USER, TERMINALS, AND WALLET
        // ─────────────────────────────────────────────────────────────

        foreach ($merchantDefinitions as $definition) {

            // 1. Create or find the owner User
            $owner = User::firstOrCreate(
                ['phone_number' => $definition['owner']['phone_number']],
                $definition['owner']
            );

            // 2. Create the Merchant linked to the owner
            $merchant = Merchant::firstOrCreate(
                ['merchant_code' => $definition['merchant']['merchant_code']],
                array_merge($definition['merchant'], ['user_id' => $owner->id])
            );

            // 3. Create each Terminal linked to this merchant
            foreach ($definition['terminals'] as $terminalData) {
                Terminal::firstOrCreate(
                    ['terminal_code' => $terminalData['terminal_code']],
                    array_merge($terminalData, ['merchant_id' => $merchant->id])
                );
            }

            // 4. Provision a Wallet for the merchant (only for approved merchants)
            //    Mirrors what ManagementController::approveMerchant() does at runtime.
            //    Pending merchants get a zero-balance wallet so the unique constraint
            //    does not fail when they are later approved via the admin endpoint.
            Wallet::firstOrCreate(
                [
                    'walletable_id'   => $merchant->id,
                    'walletable_type' => Merchant::class,
                    'currency'        => 'KES',
                ],
                [
                    'balance'              => $definition['wallet_balance'],
                    'status'               => $merchant->status === 'active' ? 'active' : 'restricted',
                    'last_transaction_at'  => $merchant->status === 'active' ? now()->subHours(rand(1, 24)) : null,
                ]
            );
        }

        $this->command->info('✅  MerchantSeeder complete.');
        $this->command->table(
            ['Merchant', 'Code', 'Status', 'Terminals', 'Wallet Balance'],
            Merchant::with(['terminals', 'wallet'])->get()->map(fn ($m) => [
                $m->business_name,
                $m->merchant_code,
                strtoupper($m->status),
                $m->terminals->count(),
                'KES ' . number_format($m->wallet?->balance ?? 0, 2),
            ])->toArray()
        );
    }
}
