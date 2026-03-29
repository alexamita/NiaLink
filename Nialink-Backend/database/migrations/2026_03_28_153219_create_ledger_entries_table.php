<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger Entries Table
     *
     * The atomic, immutable record of every balance movement in NiaLink.
     * This is the financial source of truth — not the wallets table.
     *
     * Double-entry bookkeeping:
     *   Every completed transaction produces exactly two ledger entries:
     *   one debit and one credit. The sum of all entries must always be
     *   zero — every KES that leaves one wallet enters another.
     *
     *   Example — consumer pays merchant KES 1,000 (1% fee):
     *     entry 1: wallet=consumer,  type=debit,  amount=1000.00, post_balance=4000.00
     *     entry 2: wallet=merchant,  type=credit, amount=990.00,  post_balance=1490.00
     *     (KES 10 fee stays in the NiaLink float — no ledger entry for NiaLink itself)
     *
     *   Example — P2P transfer KES 500 (no fee):
     *     entry 1: wallet=sender,   type=debit,  amount=500.00, post_balance=1500.00
     *     entry 2: wallet=receiver, type=credit, amount=500.00, post_balance=800.00
     *
     * Immutability:
     *   These rows are NEVER updated or deleted after creation.
     *   If a payment is reversed, a new pair of entries is created
     *   (debit the merchant, credit the consumer) — the originals stay.
     *   This gives a complete, tamper-evident audit trail that CBK can inspect.
     *
     * Reconstructing balance:
     *   At any point in time, the wallet balance can be independently
     *   verified by summing all ledger entries for that wallet:
     *   SELECT SUM(amount) FROM ledger_entries WHERE wallet_id = ?
     *   (credits are positive, debits are negative in the amount column)
     *
     * Foreign key dependency chain:
     *   ledger_entries.transaction_id → transactions.id
     *   ledger_entries.wallet_id      → wallets.id
     *   Both parent tables must exist before this migration runs.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Parent Transaction ──

            // The transaction header this entry belongs to.
            // Every entry must belong to a transaction — orphaned entries
            // are a data integrity violation.
            // restrictOnDelete: do not allow deleting a transaction that
            // has ledger entries — forces explicit reversal instead.
            $table->foreignUuid('transaction_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Parent transaction — cannot be deleted while entries exist');

            // ── Wallet ──
            // The specific wallet being debited or credited.
            // restrictOnDelete: wallets with ledger history cannot be deleted.
            $table->foreignUuid('wallet_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Wallet being debited or credited');

            // ── Amount ──

            // Signed amount in KES:
            //   Positive → credit (money entering the wallet)
            //   Negative → debit  (money leaving the wallet)
            //
            // Using a signed single column rather than separate debit/credit
            // columns makes balance reconstruction a simple SUM() query.
            $table->decimal('amount', 15, 2)
                ->comment('Signed: positive = credit, negative = debit');

            // The wallet balance AFTER this entry was applied.
            // Denormalised for instant balance verification without
            // summing the entire ledger history.
            // Must equal: previous post_balance + amount
            $table->decimal('post_balance', 15, 2)
                ->comment('Wallet balance immediately after this entry');

            // ── Type ──

            // Redundant with the sign of amount but kept for:
            //   - Readability in admin dashboard and support queries
            //   - Explicit filtering: WHERE entry_type = 'credit'
            //   - Backward compatibility with existing LedgerEntry model
            $table->enum('entry_type', ['debit', 'credit'])
                ->index()
                ->comment('Redundant with amount sign — kept for query clarity');

            // ── Description ──

            // Human-readable description for support and audit review.
            // Set by WalletService — examples:
            //   "Payment to Java House"
            //   "Transfer from Wanjiru Kamau"
            //   "M-Pesa top-up via STK Push"
            //   "Withdrawal to M-Pesa"
            $table->string('description')->nullable()
                ->comment('Human-readable movement description for support review');

            // ── Immutability ──

            // Only created_at — no updated_at.
            // Ledger entries are append-only. The model sets UPDATED_AT = null.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ──

            // Per-wallet history — the most common query pattern.
            // Powers transaction history screens and balance reconstruction.
            $table->index(['wallet_id', 'created_at']);

            // Per-transaction entries — used to display the debit/credit
            // pair for a single transaction in admin and support views.
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
