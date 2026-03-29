<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M-Pesa Transactions Table
     *
     * The bridge between Safaricom's asynchronous Daraja API world
     * and NiaLink's synchronous internal ledger.
     *
     * Why this table exists separately from transactions:
     *   The internal transactions table records completed financial events.
     *   This table records Daraja API interactions — which are inherently
     *   async. A row here starts as 'pending' the moment an STK Push is
     *   initiated and only becomes 'completed' when Safaricom's callback
     *   arrives, which can be seconds or minutes later.
     *
     * The two flows this table serves:
     *
     *   1. Top-up (STK Push / C2B):
     *      Consumer taps "Add Money" → NiaLink calls Daraja STK Push API
     *      → row created (status: pending, checkout_request_id set)
     *      → consumer enters M-Pesa PIN on their phone
     *      → Safaricom POSTs callback to /api/webhooks/mpesa/stk
     *      → ProcessMpesaTopUpCallback job runs
     *      → row updated (status: completed, mpesa_receipt set)
     *      → internal transactions row created
     *      → consumer wallet credited
     *
     *   2. Withdrawal / Settlement (B2C):
     *      Consumer taps "Withdraw" → wallet debited immediately
     *      → NiaLink calls Daraja B2C API
     *      → row created (status: pending)
     *      → Safaricom POSTs result to /api/webhooks/mpesa/b2c/result
     *      → ProcessMpesaB2CCallback job runs
     *      → row updated (status: completed or failed)
     *      → if failed: wallet refunded via compensating credit
     *
     * Idempotency guard:
     *   mpesa_receipt has a unique constraint. If Safaricom sends the same
     *   callback twice (they do retry), the second insert fails silently
     *   and the wallet is never double-credited. This is the most critical
     *   safety constraint in the entire M-Pesa integration.
     *
     * IMPORTANT — the callback controller must:
     *   1. Return 200 OK to Safaricom immediately (within 5 seconds)
     *   2. Dispatch ProcessMpesaTopUpCallback to the queue
     *   3. Never do wallet operations directly in the controller
     *
     * Foreign key dependency:
     *   mpesa_transactions.user_id → users.id
     *   mpesa_transactions.transaction_id → transactions.id
     *   Both parent tables must exist before this migration runs.
     */
    public function up(): void
    {
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Owner ──

            // The NiaLink user this M-Pesa interaction belongs to.
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // ── Linked Internal Transaction ──

            // Set after a successful callback — links this M-Pesa record
            // to the internal transaction row that was created as a result.
            // Null while status = pending (the internal transaction does
            // not exist yet — it is created by the callback job).
            $table->foreignUuid('transaction_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('Set after callback — links to the internal transaction row');

            // ── Flow Type ──

            // topup      → consumer funding wallet via STK Push (C2B)
            // withdrawal → consumer withdrawing to M-Pesa via B2C
            // settlement → end-of-day merchant settlement batch via B2C
            $table->enum('type', ['topup', 'withdrawal', 'settlement'])
                ->index();

            // ── Contact ──

            // The M-Pesa phone number involved in this transaction.
            // Format: 2547XXXXXXXX (Daraja requires this format).
            // Stored here because it may differ from user.phone_number
            // if the user tops up from a different number.
            $table->string('phone_number', 20)
                ->comment('M-Pesa phone in 2547XXXXXXXX format');

            // ── Financials ──

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('KES');

            // ── Daraja Reference Fields ──

            // The ID returned in the synchronous STK Push response.
            // This is NOT a payment confirmation — it only means Safaricom
            // received the request. The actual result comes via callback.
            // Used to match the async callback to this pending row.
            $table->string('checkout_request_id')->nullable()->unique()
                ->comment('STK Push sync response ID — matches async callback');

            // The M-Pesa confirmation code from a successful callback.
            // This is the code that appears on the user's SMS receipt.
            // Format: NLJ7RT61SV (alphanumeric, ~10 characters)
            //
            // UNIQUE CONSTRAINT — the idempotency guard:
            // If Safaricom sends the same callback twice, the second
            // insert attempt fails here before the wallet is credited again.
            $table->string('mpesa_receipt')->nullable()->unique()
                ->comment('M-Pesa SMS confirmation code — unique prevents double-crediting');

            // ── Status ──

            // pending   → STK Push sent, waiting for consumer PIN + callback
            // completed → callback confirmed success, wallet credited/debited
            // failed    → callback confirmed failure or consumer cancelled
            // timeout   → no callback received within the timeout window
            $table->enum('status', ['pending', 'completed', 'failed', 'timeout'])
                ->default('pending')
                ->index();

            // ── Callback Data ──

            // Full raw JSON payload from Safaricom's callback.
            // Stored verbatim for reconciliation, debugging, and CBK audits.
            // Never parse this in application code — use the structured
            // columns above instead. This is the source of truth if
            // the structured fields ever need to be reconstructed.
            $table->json('raw_callback')->nullable()
                ->comment('Verbatim Safaricom callback — for reconciliation only');

            // M-Pesa result code from the callback.
            // 0 = success. All other values indicate failure.
            // Full list: https://developer.safaricom.co.ke/docs#error-codes
            $table->string('result_code')->nullable()
                ->comment('0 = success, all others = failure — see Daraja docs');

            $table->string('result_description')->nullable()
                ->comment('Human-readable result from Safaricom callback');

            // Timestamp when the callback was processed and the wallet updated.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // ── Indexes ──

            // Per-user M-Pesa history
            $table->index(['user_id', 'status']);

            // Callback matching — the hottest query in the webhook handler
            $table->index('checkout_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};
