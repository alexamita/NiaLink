<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Create NiaLink platform admin accounts.
     *
     * These accounts log in via the web dashboard with email + password.
     * They have NO phone_number or PIN — admin auth is email-only.
     *
     * Credentials (for Postman testing):
     *
     *   Super Admin:
     *     POST /api/admin/auth/login
     *     { "email": "superadmin@nialink.co.ke", "password": "NiaLink@2026!" }
     *
     *   Admin:
     *     POST /api/admin/auth/login
     *     { "email": "admin@nialink.co.ke", "password": "NiaLink@2026!" }
     */
    public function run(): void
    {
        $admins = [
            [
                'name'         => 'Alex Amita',
                'email'        => 'superadmin@nialink.co.ke',
                'password'     => 'NiaLink@2026!',
                'primary_type' => 'merchant_admin', // closest to admin in primary_type enum
                'role'         => 'super_admin',
            ],
            [
                'name'         => 'Ops Admin',
                'email'        => 'admin@nialink.co.ke',
                'password'     => 'NiaLink@2026!',
                'primary_type' => 'merchant_admin',
                'role'         => 'admin',
            ],
        ];

        foreach ($admins as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'         => $data['name'],
                    'email'        => $data['email'],
                    'password'     => Hash::make($data['password']),
                    'primary_type' => $data['primary_type'],
                    'kyc_level'    => 'tier_3',
                    'status'       => 'active',
                    'currency'     => 'KES',
                ]
            );

            // Assign Spatie role
            $user->syncRoles([$data['role']]);

            $this->command->info("✅ Admin created: {$data['email']} (role: {$data['role']})");
        }
    }
}
