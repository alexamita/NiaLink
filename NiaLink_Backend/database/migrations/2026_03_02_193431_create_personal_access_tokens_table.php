<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * API Authentication Tokens Migration (Laravel Sanctum)
 * * This table stores the secure "Personal Access Tokens" used to authenticate
 * API requests. In NiaLink, this allows mobile apps and Merchant POS systems
 * to stay logged in securely without re-entering a PIN for every action.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * * Creates the infrastructure for token-based authentication, supporting
     * multi-device logins and restricted API abilities.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();

            /**
             * The 'tokenable' morphs allow this token to belong to ANY model.
             * In our case, both a 'User' and a 'Merchant' can own an API token.
             */
            $table->morphs('tokenable');

            // A nickname for the token (e.g., "iPhone 15" or "Main Branch POS")
            $table->text('name');

            // The actual secure hashed string used in the Authorization header
            $table->string('token', 64)->unique();

            // JSON list of what this token is allowed to do (e.g., ["view-balance", "pay"])
            $table->text('abilities')->nullable();

            // Security tracking: when was this device last active?
            $table->timestamp('last_used_at')->nullable();

            // Enforces token expiration for high-security fintech standards
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations (Rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
