<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trust Account Snapshots Table
     *
     * Records the result of NiaLink's daily CBK-mandated reconciliation.
     *
     * Legal context:
     *   As a CBK-licensed E-Money Issuer, NiaLink is legally required to:
     *   1. Hold all user wallet balances in a ring-fenced trust account
     *      at a licensed Kenyan bank (e.g. Equity Bank, KCB)
     *   2. Reconcile daily by 4:00pm EAT — comparing the trust account
     *      balance against the sum of all NiaLink wallet balances
     *   3. Rectify any deficiency by 12:00pm the following day
     *   4. Report any deficiency to CBK by 4:00pm on the day it is found
     *
     * The invariant this table monitors:
     *   trust_account_balance >= sum(wallets.balance)
     *
     *   A surplus is acceptable (NiaLink's own operating float can sit here).
     *   A deficiency means user funds are not fully backed — a CBK violation
     *   that must be escalated immediately.
     *
     * How ReconciliationService uses this table:
     *   1. Runs at 3:45pm EAT via scheduled job (15-minute buffer before deadline)
     *   2. Fetches trust account balance from bank API (or manual override in dev)
     *   3. Sums all wallet balances: SELECT SUM(balance) FROM wallets
     *   4. Calculates difference: trust_balance - wallet_sum
     *   5. Inserts a row here with the result
     *   6. If status = deficiency: alerts ops team + triggers CBK notification
     *
     * Immutability:
     *   Rows are never updated after creation — only created_at, no updated_at.
     *   cbk_notified and cbk_notified_at are the only fields that may be
     *   updated post-creation (when the CBK notification is sent).
     *   All other fields are set once at reconciliation time.
     *
     * No foreign keys:
     *   This table is a system record with no user or model owner.
     *   It must survive independently of all other tables.
     */
    public function up(): void
    {
        Schema::create('trust_account_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── The Two Numbers Being Compared ──

            // The actual KES balance in the NiaLink trust account at the
            // licensed Kenyan bank at the time of reconciliation.
            // Source: bank API response or manual entry (dev/MVP).
            $table->decimal('trust_account_balance', 15, 2)
                ->comment('Real KES in trust account at reconciliation time');

            // The sum of every wallet balance in the NiaLink system at the
            // same moment: SELECT SUM(balance) FROM wallets
            // This is what NiaLink owes to its users collectively.
            $table->decimal('total_wallet_balance', 15, 2)
                ->comment('SUM(wallets.balance) at reconciliation time');

            // ── The Result ──

            // trust_account_balance - total_wallet_balance
            // Positive → surplus  (trust has more than needed — acceptable)
            // Zero     → balanced (exactly backed — ideal)
            // Negative → deficiency (trust has less than owed — CBK violation)
            $table->decimal('difference', 15, 2)
                ->comment('trust_balance - wallet_sum. Negative = CBK violation');

            // balanced   → difference is zero or within rounding tolerance (< KES 1)
            // surplus    → trust account holds more than wallet sum (acceptable)
            // deficiency → trust account holds less than wallet sum (violation)
            $table->enum('status', ['balanced', 'surplus', 'deficiency'])
                ->default('balanced')
                ->index();

            // ── CBK Notification Tracking ──

            // Has this deficiency been formally reported to CBK?
            // Only relevant when status = deficiency.
            // Set to true by ReconciliationService after sending the CBK report.
            $table->boolean('cbk_notified')->default(false)
                ->comment('Has CBK been formally notified of this deficiency?');

            $table->timestamp('cbk_notified_at')->nullable()
                ->comment('When the CBK notification was sent');

            // ── Context ──

            // Free-text notes from the ops team.
            // Used to document the cause of a deficiency and the
            // remediation steps taken (required for CBK audit trail).
            $table->text('notes')->nullable()
                ->comment('Ops notes — required for CBK audit when deficiency found');

            // The bank from which the trust_account_balance was read.
            // Relevant when NiaLink has trust accounts at multiple banks
            // (required by CBK when float exceeds KES 100 million).
            $table->string('trust_bank')->nullable()
                ->comment('Bank name — relevant when float split across multiple banks');

            // ── Timestamp ──

            // When the reconciliation actually ran.
            // May differ slightly from created_at if the job was queued.
            $table->timestamp('reconciled_at')
                ->comment('When ReconciliationService ran — may differ from created_at');

            // Only created_at — no updated_at.
            // cbk_notified and cbk_notified_at are updated post-creation
            // but all financial figures are immutable once written.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ──

            // Deficiency monitoring — ops dashboard shows all open deficiencies
            $table->index(['status', 'cbk_notified']);

            // Date range queries — CBK may request records for a date range
            $table->index('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_account_snapshots');
    }
};
