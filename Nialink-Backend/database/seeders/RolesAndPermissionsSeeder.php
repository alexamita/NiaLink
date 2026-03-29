<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Create all roles and permissions for NiaLink.
     *
     * Run this FIRST — every other seeder assigns roles that must exist.
     *
     * Roles:
     *   super_admin    → NiaLink founders/engineers — full system access
     *   admin          → NiaLink operations staff — merchant KYC, AML review
     *   merchant_admin → Business owner — their own data only
     *   cashier        → POS staff — validate codes only (no dashboard access)
     *   consumer       → App user — pay, transfer, top up
     *
     * Permissions follow the pattern: verb-noun
     *   generate-payment-code, view-own-transactions, initiate-transfer, etc.
     */
    public function run(): void
    {
        // Reset cached roles and permissions.
        // Spatie caches roles and permissions for performance. Stale cache causes "permission does not exist" errors.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

                // =====================================================================
        // PERMISSIONS
        // Granular actions. Assigned to roles — not directly to users.
        // Naming convention: verb-noun (kebab-case)
        // =====================================================================
        $permissions = [

            // ── Payment codes ─────────────────────────────────────────────
            'generate-payment-code',    // consumer generates a Nia-Code
            'validate-payment-code',    // merchant terminal validates an incoming code

            // ── Wallet ────────────────────────────────────────────────────
            'topup-wallet',             // consumer funds wallet via M-Pesa STK Push
            'withdraw-wallet',          // consumer withdraws wallet to M-Pesa

            // ── Transactions ──────────────────────────────────────────────
            'view-own-transactions',    // user sees their own history
            'view-all-transactions',    // admin sees platform-wide ledger
            'initiate-transfer',        // consumer sends money P2P
            'initiate-bill-split',      // consumer splits a bill among contacts
            'process-refund',           // merchant_admin or admin reverses a payment

            // ── Merchant management ───────────────────────────────────────
            'manage-merchant-account',  // merchant_admin manages their own account
            'view-merchant-reports',    // merchant_admin views their reports
            'manage-merchant-staff',    // merchant_admin adds/removes cashiers
            'approve-merchant',         // admin approves merchant KYC
            'suspend-merchant',         // admin suspends a merchant account

            // ── Terminal management ───────────────────────────────────────
            'view-terminals',           // admin lists all terminals
            'lock-terminal',            // admin locks a specific terminal

            // ── User management ───────────────────────────────────────────
            'manage-users',             // admin creates/suspends/closes accounts
            'view-users',               // admin reads user records
            'suspend-user',             // admin suspends a specific user
            'manage-kyc',               // admin upgrades/downgrades KYC tiers
            'manage-limit-overrides',   // admin sets per-user limit exceptions

            // ── Wallet management (admin) ──────────────────────────────────
            'view-all-wallets',         // admin views float monitor
            'freeze-wallet',            // admin freezes wallet for AML hold

            // ── AML compliance ────────────────────────────────────────────
            'view-aml-flags',           // admin views AML flag queue
            'review-aml-flags',         // admin claims, clears, or reports flags to FRC

            // ── Audit ─────────────────────────────────────────────────────
            'view-audit-logs',          // admin reads the event trail

            // ── Float & reconciliation ────────────────────────────────────
            'view-float',               // admin views float transaction history
            'view-reconciliations',     // admin views daily reconciliation snapshots

            // ── Roles (super_admin only) ──────────────────────────────────
            'manage-roles',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // =====================================================================
        // ROLES — principle of least privilege.
        // Each role gets exactly what it needs, nothing more.
        // syncPermissions() replaces all existing permissions on re-run,
        // making this seeder safe to run multiple times.
        // =====================================================================

        // Consumer — standard mobile app user
        $consumer = Role::firstOrCreate(['name' => 'consumer']);
        $consumer->syncPermissions([
            'generate-payment-code',
            'topup-wallet',
            'withdraw-wallet',
            'view-own-transactions',
            'initiate-transfer',
            'initiate-bill-split',
        ]);

        // Cashier — merchant POS staff, terminal access only
        // Cannot access the web dashboard — terminal auth is separate
        $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $cashier->syncPermissions([
            'validate-payment-code',
            'view-own-transactions',
        ]);

        // Merchant admin — owns or manages a merchant account
        $merchantAdmin = Role::firstOrCreate(['name' => 'merchant_admin']);
        $merchantAdmin->syncPermissions([
            'validate-payment-code',
            'view-own-transactions',
            'view-merchant-reports',
            'manage-merchant-account',
            'manage-merchant-staff',
            'process-refund',
            'withdraw-wallet',
        ]);

        // Admin — NiaLink platform operations staff
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions([
            'view-users',
            'manage-users',
            'suspend-user',
            'manage-kyc',
            'manage-limit-overrides',
            'view-all-transactions',
            'view-audit-logs',
            'process-refund',
            'approve-merchant',
            'suspend-merchant',
            'view-all-wallets',
            'freeze-wallet',
            'view-aml-flags',
            'review-aml-flags',
            'view-float',
            'view-reconciliations',
            'view-terminals',
            'lock-terminal',
        ]);

        // Super admin — full access, assign sparingly
        // Only NiaLink founders and senior engineers
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->syncPermissions(Permission::all());

        $this->command->info('');
        $this->command->info('✅ Roles and permissions seeded.');
        $this->command->table(
            ['Role', 'Permission count'],
            [
                ['consumer',       $consumer->permissions->count()],
                ['cashier',        $cashier->permissions->count()],
                ['merchant_admin', $merchantAdmin->permissions->count()],
                ['admin',          $admin->permissions->count()],
                ['super_admin',    $superAdmin->permissions->count()],
            ]
        );
    }
}
