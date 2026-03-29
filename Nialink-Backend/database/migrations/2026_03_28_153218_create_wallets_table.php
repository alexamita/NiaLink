<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallets Table
     *
     * The polymorphic balance store for the NiaLink internal ledger.
     *
     * Why polymorphic:
     *   Both consumers (User) and businesses (Merchant) need wallets,
     *   but they are fundamentally different entities. A polymorphic
     *   relationship lets one table serve both cleanly:
     *     walletable_type = 'App\Models\User'     → consumer wallet
     *     walletable_type = 'App\Models\Merchant' → merchant wallet
     *
     * The float invariant (CBK requirement):
     *   sum(wallets.balance) must always equal the NiaLink trust account
     *   balance at the bank. Verified daily by ReconciliationService at
     *   3:45pm EAT. Any deficiency must be reported to CBK by 4pm.
     *
     * CRITICAL — never write to balance directly:
     *   All balance changes must go through WalletService::debit() and
     *   WalletService::credit() which use lockForUpdate() to prevent race
     *   conditions and create a LedgerEntry for every movement.
     *   Direct balance updates bypass the ledger and break the audit trail.
     *
     * Foreign key dependency:
     *   No direct foreign keys — polymorphic relationship.
     *   Users and merchants tables must exist before this migration runs.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Polymorphic Owner ──

            // The type of model that owns this wallet.
            // Values: 'App\Models\User' or 'App\Models\Merchant'
            $table->string('walletable_type')
                ->comment('App\Models\User or App\Models\Merchant');

            // The UUID of the owning model.
            // uuid type to match both users.id and merchants.id (both UUIDs).
            $table->uuid('walletable_id')
                ->comment('UUID of the owning User or Merchant');

            // ── Balance ──

            // Current spendable balance in KES.
            // NEVER modify this column directly — always use WalletService.
            // Precision: 15 digits total, 2 decimal places (supports up to
            // KES 9,999,999,999,999.99 — well above CBK wallet limits).
            $table->decimal('balance', 15, 2)->default(0.00)
                ->comment('Current balance — ONLY modify via WalletService');

            // Running lifetime totals — never decremented, only incremented.
            // Used for:
            //   - KYC tier upgrade eligibility checks
            //   - Fraud velocity analysis
            //   - CBK annual transaction volume reporting
            $table->decimal('total_credited', 15, 2)->default(0.00)
                ->comment('Lifetime inbound total — never decremented');

            $table->decimal('total_debited', 15, 2)->default(0.00)
                ->comment('Lifetime outbound total — never decremented');

            // ── Currency ──

            // Locked to KES for MVP. Column exists now so adding multi-currency
            // later is a data migration, not a schema change.
            $table->string('currency', 3)->default('KES');

            // ── Status & Freezing ──

            // active → normal operation, can send and receive
            // frozen → blocked by admin (fraud investigation or CBK hold)
            //
            // Frozen wallets cannot debit OR credit — WalletService enforces this.
            // A frozen consumer cannot pay. A frozen merchant cannot receive payments.
            $table->enum('status', ['active', 'frozen'])
                ->default('active')
                ->index();

            // Reason stored for CBK compliance and support audit trail.
            // Required when status is set to frozen.
            $table->string('freeze_reason')->nullable()
                ->comment('Required when freezing — stored for CBK compliance');

            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('unfrozen_at')->nullable();

            // ── Activity Tracking ──

            // Updated on every successful debit or credit.
            // Used for dormant wallet detection and fraud pattern analysis.
            $table->timestamp('last_transaction_at')->nullable()
                ->comment('Updated on every successful WalletService operation');

            $table->timestamps();

            // ── Constraints ──

            // One wallet per owner — a User cannot have two wallets,
            // nor can a Merchant.
            $table->unique(['walletable_type', 'walletable_id']);

            // Fast lookup for the polymorphic relationship query.
            // Used on every payment: $user->wallet, $merchant->wallet
            $table->index(['walletable_type', 'walletable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
