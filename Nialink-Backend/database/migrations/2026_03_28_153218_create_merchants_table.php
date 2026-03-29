<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchants Table
     *
     * Represents a business entity registered on NiaLink.
     *
     * Lifecycle:
     *   pending   → submitted, awaiting admin KYC review
     *   active    → verified, can accept Nia-Code payments
     *   suspended → temporarily blocked by admin
     *   rejected  → failed KYC, must resubmit
     *
     * Each merchant has:
     *   - One or more Terminals (pre-registered POS units)
     *   - One polymorphic Wallet for collected funds
     *
     * Payment flow at POS:
     *   Consumer shows 6-digit Nia-Code → cashier's pre-registered
     *   terminal submits terminal_code + nialink_code + amount.
     *   The merchant_code is internal only — never typed at checkout.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Owner of this merchant account
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // ── Business Identity ──

            $table->string('business_name');

            // Internal reference only — used in admin dashboard, reports,
            // API responses. Never typed at POS — terminals handle that.
            $table->string('merchant_code')->unique()
                ->comment('Internal reference only — not used at POS checkout');

            $table->string('category')->nullable()
                ->comment('e.g. retail, restaurant, pharmacy, services');

            // ── CBK Compliance ──

            $table->string('kra_pin', 11)->nullable()->unique()
                ->comment('Kenya Revenue Authority PIN — required for verification');

            $table->string('business_license_no')->nullable()->unique()
                ->comment('Kenya business registration number');

            $table->timestamp('verified_at')->nullable();

            // ── API & Webhook Integration ──

            // Signing secret for webhook payloads — never in API responses
            $table->string('api_key')->nullable()
                ->comment('HMAC signing secret for webhook payloads');

            $table->string('webhook_url')->nullable()
                ->comment('Endpoint for NiaLink payment.completed callbacks');

            // ── Settlement Details ──

            $table->string('settlement_bank_name')->nullable();
            $table->string('settlement_bank_account_no')->nullable();

            $table->string('mpesa_paybill', 10)->nullable()
                ->comment('M-Pesa Paybill for settlement payouts');

            $table->string('mpesa_till_number', 10)->nullable()
                ->comment('M-Pesa Buy Goods till for settlement payouts');

            // ── Status ──

            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])
                ->default('pending')
                ->index();

            $table->text('rejection_reason')->nullable()
                ->comment('Shown to merchant when status = rejected');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
