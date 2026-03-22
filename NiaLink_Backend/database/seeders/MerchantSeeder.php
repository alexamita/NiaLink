<?php

namespace Database\Seeders;


use App\Models\Merchant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;


/**
 * MerchantSeeder [Database Flow]
 * Populates the users table with sample merchants to facilitate
 * payment testing in the NiaLink ecosystem.
 */
class MerchantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $merchants = [
            ['name' => 'Naivas Supermarket', 'code' => '778899'],
            ['name' => 'Quickmart CBD', 'code' => 'QKM-001'],
            ['name' => 'Artcaffé Westlands', 'code' => 'ART-555'],
            ['name' => 'Java House Galleria', 'code' => 'JAV-101'],
            ['name' => 'Shell Petrol Station', 'code' => 'SHL-777'],
            ['name' => 'Zucchini Green Grocers', 'code' => 'ZUC-202'],
            ['name' => 'Carrefour Sarit', 'code' => 'CAR-999'],
            ['name' => 'CleanShelf Limuru', 'code' => 'CLN-303'],
            ['name' => 'TotalEnergies Hurlingham', 'code' => 'TOT-111'],
            ['name' => 'Safaricom Shop', 'code' => 'SAF-888'],
        ];

        foreach ($merchants as $data) {
            Merchant::updateOrCreate(
                ['merchant_code' => $data['code']], // Unique Identifier
                [
                    'business_name' => $data['name'],
                    // Standard prefix for NiaLink production keys
                    'api_key'       => 'nl_key_' . Str::random(40),
                    'balance'       => 0.00,
                ]
            );
        }

        $this->command->info('10 NiaLink Merchants created successfully!');
    }
}
