<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * High-level transaction headers tracking intent and status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id(); // Unique Transaction Reference Number

            // Unique External Reference (e.g., NL890JLX2)
            $table->string('reference')->unique()->index();

            // Participants
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('merchant_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('terminal_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('recipient_id')->nullable()->constrained('users')->onDelete('restrict');

            // Financial Data
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('KES');

            // Nia-Code Context
            $table->string('nialink_code', 6)->nullable()->index();

            // Logic & Status
            $table->string('type')->index(); // p2m, p2p, withdrawal, deposit
            $table->string('status')->default('pending')->index(); // pending, completed, failed, expired
            $table->string('description')->nullable();

            $table->timestamps(); // tracks 'created_at' (generation time) and 'updated_at' (completion time)
        });
    }

    /**
     * Reverse the migrations (Rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
