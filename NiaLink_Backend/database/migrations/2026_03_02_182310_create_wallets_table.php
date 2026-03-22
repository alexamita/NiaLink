<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic wallet system supporting both Users and Merchants.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            // Polymorphic Link: walletable_id (int) and walletable_type (string)
            // This allows the wallet to belong to a 'User' or a 'Merchant'
            $table->morphs('walletable');

            // Financial Balance
            // Using 15,2 to handle large volumes (up to 9 trillion KES)
            $table->decimal('balance', 15, 2)->default(0.00);

            // Currency & Localisation
            $table->string('currency', 3)->default('KES');

            // Safety & Integrity
            $table->string('status')->default('active')->index(); // active, frozen, restricted
            $table->timestamp('last_transaction_at')->nullable();

            $table->timestamps();

            // Ensure one entity only has one wallet per currency
            $table->unique(['walletable_id', 'walletable_type', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
