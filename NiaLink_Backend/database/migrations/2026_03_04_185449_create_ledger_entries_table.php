<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * The atomic double-entry record system.
 * Every movement of money consists of at least one debit and one credit.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            // Link to the high-level transaction header
            $table->foreignId('transaction_id')->constrained()->onDelete('cascade');

            // The specific wallet affected
            $table->foreignId('wallet_id')->constrained()->onDelete('restrict');

            // The 'Math'
            // Positive for Credit (Money in), Negative for Debit (Money out)
            $table->decimal('amount', 15, 2);

            // Snapshot of the balance *after* this entry for audit trails
            $table->decimal('post_balance', 15, 2);

            // Entry Metadata
            $table->string('entry_type')->index(); // 'debit' or 'credit'
            $table->string('description')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
