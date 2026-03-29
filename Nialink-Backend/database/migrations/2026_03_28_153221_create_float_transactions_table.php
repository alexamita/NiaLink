<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Float Transactions Table
     *
     * Records every movement of real KES into or out of the NiaLink
     * trust account at the bank. This is the external money ledger —
     * the counterpart to ledger_entries which tracks internal movements.
     *
     * The relationship between the three financial tables:
     *
     *   float_transactions → real KES crossing the NiaLink boundary
     *                        (money entering or leaving the trust account)
     *
     *   ledger_entries     → internal KES moving between NiaLink wallets
     *                        (consumer pays merchant — no money crosses boundary)
     *
     *   trust_account_snapshots → daily proof that float_transactions
     *                             and ledger_entries are in sync
     *
     * When a float transaction is created:
     *
     *   Top-up (inflow):
     *     Consumer's M-Pesa → NiaLink trust account at Equity Bank
     *     → float_transactions row (type: topup_credit, amount: +500)
     *     → ledger_entries row (consumer wallet credited +500)
     *     Result: trust account up KES 500, consumer wallet up KES 500 ✓
     *
     *   Withdrawal (outflow):
     *     NiaLink trust account → Consumer's M-Pesa
     *     → float_transactions row (type: withdrawal_debit, amount: -500)
     *     → ledger_entries row (consumer wallet debited -500)
     *     Result: trust account down KES 500, consumer wallet down KES 500 ✓
     *
     *   Settlement (outflow):
     *     NiaLink trust account → Merchant's bank account or M-Pesa
     *     → float_transactions row (type: settlement_debit, amount: -990)
     *     (ledger_entries already written when the payment was made —
     *      settlement is just the physical movement of the merchant's balance)
     *
     * Running balance:
     *   balance_before and balance_after form an unbroken chain.
     *   Each row's balance_before must equal the previous row's balance_after.
     *   This chain can be verified at any time to reconstruct the full
     *   trust account history without calling the bank's API.
     *
     * Immutability:
     *   Rows are never updated or deleted. No updated_at column.
     *   Corrections are made by creating an adjustment row with a note.
     */
    public function up(): void
    {
        Schema::create('float_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Flow Type ──

            // topup_credit      → M-Pesa STK Push confirmed, KES entered trust account
            // withdrawal_debit  → B2C payout sent, KES left trust account
            // settlement_debit  → Merchant settlement batch sent, KES left trust account
            // adjustment        → Manual correction with CBK approval and documentation
            $table->enum('type', [
                'topup_credit',
                'withdrawal_debit',
                'settlement_debit',
                'adjustment',
            ])->index();

            // ── Amount ──

            // Always positive — the type column indicates direction.
            // Keeping amount positive avoids sign confusion when reading
            // records directly in the database or in reports.
            $table->decimal('amount', 15, 2)
                ->comment('Always positive — type column indicates inflow vs outflow');

            $table->string('currency', 3)->default('KES');

            // ── Running Balance ──

            // Trust account balance immediately BEFORE this transaction.
            // Must equal the balance_after of the previous row.
            // This unbroken chain allows full trust account reconstruction
            // without querying the bank API.
            $table->decimal('balance_before', 15, 2)
                ->comment('Trust account balance before this movement');

            // Trust account balance immediately AFTER this transaction.
            // For inflows:  balance_before + amount
            // For outflows: balance_before - amount
            $table->decimal('balance_after', 15, 2)
                ->comment('Trust account balance after this movement');

            // ── Source Links ──

            // The M-Pesa transaction that triggered this float movement.
            // Links topup_credit and withdrawal_debit rows back to the
            // specific Daraja API interaction that caused the real money movement.
            // Null for settlement_debit (batch) and adjustment rows.
            $table->uuid('mpesa_transaction_id')->nullable()
                ->comment('Links to mpesa_transactions.id — null for settlements');

            // The internal transaction this float movement corresponds to.
            // For topup_credit: the topup transaction row
            // For withdrawal_debit: the withdrawal transaction row
            // Null for settlement_debit (batch covers multiple transactions)
            // and adjustment rows.
            $table->foreignUuid('transaction_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('Links to transactions.id — null for batch settlements');

            // ── Reference & Notes ──

            // Unique reference for this float movement.
            // Format: FLT-YYYYMMDD-XXXXXXXX
            // Used for reconciliation queries and CBK audit trail.
            $table->string('reference')->unique()
                ->comment('Float movement reference — format: FLT-YYYYMMDD-XXXXXXXX');

            // Required for adjustment rows — must document why the
            // manual correction was made and who authorised it.
            // Recommended for all rows as reconciliation context.
            $table->text('notes')->nullable()
                ->comment('Required for adjustments — documents authorisation and reason');

            // The bank account this movement was associated with.
            // Relevant when NiaLink has multiple trust accounts.
            $table->string('trust_bank')->nullable()
                ->comment('Which trust account bank this movement affected');

            // ── Immutable Timestamp ──

            // Only created_at — no updated_at. These records are immutable.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ──

            // Type + date range — CBK reconciliation queries
            $table->index(['type', 'created_at']);

            // M-Pesa linkage lookup
            $table->index('mpesa_transaction_id');

            // Daily float movement reports
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('float_transactions');
    }
};
