<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manages individual POS units or digital checkout points belonging to a merchant.
     */
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');

            // Terminal Identity
            $table->string('name'); // e.g., "Till 01", "Westlands Branch - Register 4" or "Main Checkout"
            $table->string('terminal_code')->unique()->index(); // Public ID for the POS interface

            // Security & Integration
            $table->string('terminal_secret')->nullable()
                ->comment('Unique secret for this specific hardware/instance to sign requests');

            // Status & Location
            $table->string('status')->default('active')->index(); // active, inactive, locked
            $table->string('location_note')->nullable()->comment('Physical placement or branch info');

            // Metadata
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
