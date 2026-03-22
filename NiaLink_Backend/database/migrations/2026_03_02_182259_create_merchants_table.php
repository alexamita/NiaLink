<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * Manages business entities, KYC compliance, and settlement routing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * * Creates the infrastructure for merchant accounts, including
     * identification and financial balance tracking.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            // Link to the primary User who owns or has the power to manage the merchant's business account.
            $table->foreignId('user_id')->constrained()->onDelete('restrict');

            // Business Identity
            $table->string('business_name');
            $table->string('merchant_code')->unique()->index(); // Public ID for Business identity

            // --- Compliance (KRA & KYC) ---
            $table->string('kra_pin')->nullable()->unique();
            $table->string('business_license_no')->nullable();
            $table->string('category')->default('general'); // e.g., retail, fuel, bills
            $table->timestamp('verified_at')->nullable(); // Set by Admin after KYC

            // --- API & Security---
            $table->string('api_key')->nullable()->unique()->comment('Secret token for authenticating server-side POS requests');;
            $table->string('webhook_url')->nullable()->comment('Endpoint for Instant Payment Notifications (IPN) to merchant systems');

            // --- Operational Status ---
            $table->string('status')->default('pending')->index(); // pending, active, suspended

            // Financial Settlement (Where their money eventually goes)
            $table->string('settlement_bank')->nullable();
            $table->string('settlement_account')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.(Rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
