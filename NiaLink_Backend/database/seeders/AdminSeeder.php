<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['phone_number' => '0700000000'],
            [
                'name'      => 'NiaLink Admin',
                'email'     => 'admin@nialink.co.ke',
                'password'  => Hash::make('Admin@1234'),  // Change after first login
                'pin'       => Hash::make('0000'),
                'user_role' => 'admin',
                'status'    => 'active',
                'kyc_level' => 'tier_3',
                'currency'  => 'KES',
            ]
        );

        $this->command->info('Admin seeded — email: admin@nialink.co.ke, password: Admin@1234');
    }
}
