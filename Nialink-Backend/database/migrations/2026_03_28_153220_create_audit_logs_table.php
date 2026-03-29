<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit Logs Table
     *
     * Append-only compliance and security trail for every significant
     * event on the NiaLink platform.
     *
     * What belongs here vs ledger_entries:
     *   ledger_entries → financial movements (money in, money out)
     *   audit_logs     → behavioural events (who did what, when, from where)
     *
     * Examples of events logged here:
     *   Security:     login, logout, failed_pin_attempt, device_trusted,
     *                 device_revoked, pin_changed, account_locked
     *   Payments:     code_generated, payment_claimed, payment_completed,
     *                 payment_failed, payment_reversed
     *   Merchant:     merchant_approved, merchant_suspended, terminal_locked,
     *                 terminal_unlocked, webhook_fired, webhook_failed
     *   Admin:        user_suspended, kyc_tier_upgraded, limit_override_set,
     *                 wallet_frozen, wallet_unfrozen
     *   Compliance:   aml_flag_raised, aml_flag_cleared, cbk_report_submitted,
     *                 reconciliation_completed, float_deficiency_detected
     *
     * Immutability:
     *   Rows are NEVER updated or deleted after creation.
     *   No updated_at column — enforced at schema level.
     *   The model sets const UPDATED_AT = null.
     *
     * CBK requirement:
     *   E-money issuers must maintain a complete audit trail of all
     *   account activity. These records must be retained for a minimum
     *   of 5 years and made available to CBK on request.
     *
     * Foreign key dependency:
     *   audit_logs.user_id → users.id
     *   Users table must exist before this migration runs.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Actor ──

            // The user who performed the action.
            // Nullable because:
            //   1. System/scheduled events have no human actor
            //      (e.g. reconciliation_completed, code_expired)
            //   2. nullOnDelete: logs survive if the user account is deleted —
            //      financial compliance records must outlive user accounts
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('Actor — null for system events or deleted users');

            // ── Event ──

            // What happened. Use dot-notation for namespacing:
            //   'payment_code.generated'
            //   'transaction.completed'
            //   'merchant.approved'
            //   'device.revoked'
            //   'wallet.frozen'
            // Consistent naming makes filtering and alerting reliable.
            $table->string('action')
                ->index()
                ->comment('Dot-notation event name e.g. payment_code.generated');

            // ── Subject ──

            // The model type affected by this event.
            // Examples: 'App\Models\Transaction', 'App\Models\Merchant'
            // Nullable for events with no specific model subject
            // (e.g. login attempts, system health events).
            $table->string('resource_type')->nullable()
                ->comment('Affected model class e.g. App\Models\Transaction');

            // The ID of the affected model.
            // UUID type to support all NiaLink models (all use UUID PKs).
            $table->uuid('resource_id')->nullable()
                ->comment('UUID of the affected model row');

            // ── Context ──

            // Flexible JSON payload for event-specific details.
            // Examples:
            //   code_generated:    { code_length: 6, expires_in: 120 }
            //   payment_completed: { amount: 1000, fee: 10, merchant: "Java House" }
            //   failed_pin:        { attempt_count: 3, max_attempts: 5 }
            //   merchant_approved: { merchant_code: "NL-KE001", kra_pin: "A001..." }
            // Never store sensitive data here (no PINs, no full card numbers).
            $table->json('metadata')->nullable()
                ->comment('Event-specific context — never store sensitive credentials');

            // ── Network Context ──

            // IP address of the request that triggered this event.
            // IPv6-safe: 45 characters covers full IPv6 notation.
            // Null for system/scheduled events with no HTTP context.
            $table->string('ip_address', 45)->nullable()
                ->comment('IPv6-safe — null for system events');

            $table->string('user_agent')->nullable()
                ->comment('Browser or app agent string');

            // ── Immutable Timestamp ──

            // Only created_at — no updated_at.
            // useCurrent() sets the DB default to NOW() so the timestamp
            // is set at the database level, not application level.
            // This prevents clock skew issues in distributed deployments.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ──

            // Per-user activity timeline — most common audit query.
            $table->index(['user_id', 'created_at']);

            // Event-type filtering — powers admin alert rules.
            // e.g. "show all failed_pin_attempt events in the last hour"
            $table->index(['action', 'created_at']);

            // Per-resource history — "show all events affecting merchant X"
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
