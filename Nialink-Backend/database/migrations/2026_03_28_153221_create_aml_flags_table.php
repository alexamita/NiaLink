<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AML Flags Table
     *
     * Anti-Money Laundering monitoring records.
     *
     * Legal context:
     *   As a CBK-licensed E-Money Issuer, NiaLink is required to comply
     *   with the Proceeds of Crime and Anti-Money Laundering Act (POCAMLA)
     *   and the Financial Reporting Centre (FRC) Kenya regulations.
     *   This includes:
     *   - Monitoring transactions for suspicious patterns
     *   - Filing Suspicious Transaction Reports (STRs) with the FRC
     *   - Maintaining records of all flagged activity for 7 years
     *   - Freezing accounts under investigation on CBK instruction
     *
     * Who creates these rows:
     *   AmlService — called automatically before every transaction.
     *   Admin users — can manually flag accounts via the dashboard.
     *
     * Who reviews these rows:
     *   NiaLink compliance team via the admin dashboard.
     *   Critical flags (severity = critical) trigger immediate ops alerts.
     *
     * What happens after flagging:
     *   open         → awaiting compliance team review
     *   under_review → compliance team is investigating
     *   cleared      → investigation complete, no suspicious activity found
     *   reported     → Suspicious Transaction Report filed with FRC Kenya
     *
     * Flag types and what triggers them (AmlService):
     *
     *   high_value:
     *     Single transaction ≥ KES 1,000,000 (CBK reporting threshold).
     *     ALLOWED but flagged — does not block the transaction.
     *     Compliance team reviews and files STR if warranted.
     *
     *   structuring:
     *     3+ transactions just below KES 500,000 within 24 hours.
     *     Pattern suggests deliberate splitting to avoid reporting threshold.
     *     BLOCKED — transaction is rejected until flag is cleared.
     *
     *   velocity:
     *     10+ transactions within 60 minutes.
     *     Suggests account compromise or automated fraud.
     *     BLOCKED — transaction is rejected until flag is cleared.
     *
     *   unusual_pattern:
     *     Behaviour deviates significantly from the user's history.
     *     e.g. first international transaction, sudden large amounts.
     *     FLAGGED — allowed but queued for review.
     *
     *   pep_match:
     *     User matches a Politically Exposed Person screening database.
     *     Requires Enhanced Due Diligence (EDD) before further transactions.
     *     BLOCKED pending EDD completion.
     *
     *   sanctions_match:
     *     User matches a sanctions screening list (UN, OFAC, EU).
     *     Account must be frozen immediately and CBK notified.
     *     BLOCKED — most severe flag type.
     *
     * Foreign key dependency:
     *   aml_flags.user_id        → users.id
     *   aml_flags.transaction_id → transactions.id
     *   aml_flags.reviewed_by    → users.id
     *   All parent tables must exist before this migration runs.
     */
    public function up(): void
    {
        Schema::create('aml_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Subject ──

            // The user whose activity triggered this flag.
            // cascadeOnDelete: if a user is hard-deleted, their AML flags
            // are removed. In practice this should never happen — soft
            // deletes mean the user row stays. Hard deletion of a flagged
            // user would itself be an AML concern.
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // The specific transaction that triggered this flag.
            // Null for flags raised on account-level patterns
            // (e.g. pep_match, sanctions_match which are not tied
            // to a single transaction).
            $table->foreignUuid('transaction_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('The triggering transaction — null for account-level flags');

            // ── Classification ──

            $table->enum('flag_type', [
                'high_value',       // Single tx ≥ KES 1M — flagged, not blocked
                'structuring',      // Split transactions to avoid threshold — blocked
                'velocity',         // Too many transactions too fast — blocked
                'unusual_pattern',  // Behaviour outside user history — flagged
                'pep_match',        // Politically Exposed Person match — blocked
                'sanctions_match',  // Sanctions list match — blocked, freeze account
            ])->index();

            // Severity drives the ops response:
            //   low      → log and monitor, no immediate action
            //   medium   → compliance team reviews within 24 hours
            //   high     → compliance team reviews within 4 hours, alert sent
            //   critical → immediate action required, ops paged
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])
                ->default('low')
                ->index();

            // ── Lifecycle ──
            // open         → created by AmlService, awaiting review
            // under_review → compliance team has claimed this flag
            // cleared      → reviewed, no suspicious activity confirmed
            // reported     → STR filed with FRC Kenya
            $table->enum('status', ['open', 'under_review', 'cleared', 'reported'])
                ->default('open')
                ->index();

            // ── Detail ──
            // Human-readable description of why this flag was raised.
            // Set by AmlService — examples:
            //   "Transaction of KES 1,500,000 exceeds CBK reporting threshold"
            //   "4 transactions between KES 400,000–499,000 within 24 hours"
            //   "12 transactions in the last 60 minutes from device TRM-KE001"
            $table->text('notes')
                ->comment('Why this flag was raised — set by AmlService');

            // Compliance team notes added during review.
            // Documents the investigation findings and decision rationale.
            // Required before status can be set to cleared or reported.
            $table->text('review_notes')->nullable()
                ->comment('Compliance team investigation notes — required before clearing');

            // FRC Kenya Suspicious Transaction Report reference number.
            // Set when status = reported. Required for CBK audit trail.
            $table->string('frc_reference')->nullable()
                ->comment('FRC Kenya STR reference number — set when status = reported');

            // ── Review Tracking ──

            // The admin user who reviewed and resolved this flag.
            // Null while status = open or under_review.
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Admin who resolved this flag');

            $table->timestamp('reviewed_at')->nullable()
                ->comment('When the flag was resolved');

            // ── Immutable Creation Timestamp ──

            // Only created_at — the flag creation time is immutable.
            // reviewed_at and status updates are the mutable fields.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ──

            // Open flags dashboard — compliance team's primary view
            $table->index(['status', 'severity', 'created_at']);

            // Per-user flag history
            $table->index(['user_id', 'created_at']);

            // Blocked transaction lookup — used to unblock after clearing
            $table->index(['flag_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_flags');
    }
};
