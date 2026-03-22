<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base User & Authentication Migration
     */
    public function up(): void
    {
        // 1. Users Table - Core Identity
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // --- Ownership & Identity ---
            $table->string('name');
            $table->string('phone_number')->unique()->index();
            // App PIN is the primary Auth for both Login & Payments
            $table->string('pin_hash')->comment('Unified PIN for App Access and Transaction Auth');
            // Device Binding -- for security
            $table->string('device_id')->nullable()->unique()->index()
                ->comment('Unique Hardware ID to prevent PIN use on other devices');
            // Push-to-Approve Infrastructure
            $table->string('fcm_token')->nullable()->comment('Firebase token for Push Auth'); // for targeted push notifications
            // Defines the user's primary relationship with the platform
            $table->string('user_role')->default('consumer')->index(); // 'consumer', 'merchant_admin', 'staff'

            // Flag to check if they are allowed to have a personal wallet
            $table->boolean('has_personal_wallet')->default(true);

            // --- Transaction Limits Management (Editable by User/Admin) ---
            // P2M (Person-to-Merchant) Limits - For "Nia-Code" POS/Online
            $table->decimal('daily_limit_p2m', 15, 2)->default(50000.00);
            // P2P (Person-to-Person) Limits - lower to prevent fraud/social engineering
            $table->decimal('daily_limit_p2p', 15, 2)->default(20000.00);
            // ATM/Withdrawal Limits
            $table->decimal('daily_limit_atm', 15, 2)->default(10000.00);
            // Global Transaction Count Limit (default set to 20/day)
            $table->integer('daily_transaction_count_limit')->default(20);

            // --- Settings ---
            $table->boolean('biometric_enabled')->default(false);

            // --- STATUS & KYC ---
            $table->string('status')->default('active')->index(); // active, suspended/blocked, flagged
            $table->string('kyc_level')->default('tier_1'); // tier_1 (Basic), tier_2 (ID Verified), tier_3 (Full)
            $table->string('currency', 3)->default('KES');

            // --- Audit & Tracking ---
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // 2. OTP & Reset Tokens (Handles Phone Verification & PIN Resets)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('phone_number')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // 3. Sessions Table (Standard Laravel Security)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
