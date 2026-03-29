<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Terminal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TerminalSeeder extends Seeder
{
    /**
     * Create test terminals for active merchants.
     *
     * Terminals authenticate at POS using terminal_code + terminal_secret.
     * The terminal_secret is hashed in the DB — the plaintext is shown below
     * for Postman testing only.
     *
     * For Postman payment testing:
     *   POST /api/pos/payment
     *   {
     *     "nialink_code":    "XXXXXX",       ← from consumer generate endpoint
     *     "terminal_code":   "TRM-JAVAHOUSE-01",
     *     "terminal_secret": "test-secret-java-1",
     *     "amount":          500
     *   }
     *
     * Terminals created:
     *   Java House → Main Till, Westlands Till
     *   Naivas     → Main Till
     */
    public function run(): void
    {
        $terminals = [
            // Java House terminals
            [
                'merchant_kra' => 'A001234567B',
                'name'         => 'Main Till',
                'terminal_code'   => 'TRM-JAVAHOUSE-01',
                'terminal_secret' => 'test-secret-java-1', // plaintext for Postman
                'location_note'   => 'Ground floor — Westlands branch',
                'status'          => 'active',
            ],
            [
                'merchant_kra' => 'A001234567B',
                'name'         => 'Drive Through Till',
                'terminal_code'   => 'TRM-JAVAHOUSE-02',
                'terminal_secret' => 'test-secret-java-2',
                'location_note'   => 'Drive-through window',
                'status'          => 'active',
            ],
            // Naivas terminal
            [
                'merchant_kra' => 'B009876543A',
                'name'         => 'Checkout Till 1',
                'terminal_code'   => 'TRM-NAIVAS-01',
                'terminal_secret' => 'test-secret-naivas-1',
                'location_note'   => 'Main checkout area',
                'status'          => 'active',
            ],
            // Locked terminal — for testing locked terminal rejection
            [
                'merchant_kra' => 'A001234567B',
                'name'         => 'Locked Till',
                'terminal_code'   => 'TRM-JAVAHOUSE-LOCKED',
                'terminal_secret' => 'test-secret-java-locked',
                'location_note'   => 'Decommissioned — test locked rejection',
                'status'          => 'locked',
            ],
        ];

        foreach ($terminals as $data) {
            $merchant = Merchant::where('kra_pin', $data['merchant_kra'])->first();

            if (! $merchant) {
                $this->command->warn("⚠️  Merchant with KRA PIN {$data['merchant_kra']} not found — skipping terminal.");
                continue;
            }

            Terminal::firstOrCreate(
                ['terminal_code' => $data['terminal_code']],
                [
                    'merchant_id'     => $merchant->id,
                    'name'            => $data['name'],
                    'terminal_code'   => $data['terminal_code'],
                    'terminal_secret' => $data['terminal_secret'], // hashed by boot()
                    'location_note'   => $data['location_note'],
                    'status'          => $data['status'],
                ]
            );

            $statusIcon = $data['status'] === 'active' ? '✅' : '🔒';
            $this->command->info(
                "{$statusIcon} Terminal: {$data['terminal_code']} | " .
                "Merchant: {$merchant->business_name} | " .
                "Secret (plaintext): {$data['terminal_secret']}"
            );
        }
    }
}
