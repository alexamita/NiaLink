<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * Audit Logs Migration
 * * This migration creates the security trail for NiaLink.
 * In a financial ecosystem, tracking "who did what and from where" is
 * essential for fraud detection, troubleshooting, and regulatory compliance.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * * Sets up the logging infrastructure to track user activity
     * without affecting core financial transaction data.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();// Unique log entry identifier

            /**
             * The Actor
             *
             * Foreign Key: The user responsible for the action.
             * We use 'set null' on delete so that even if a user account is removed,
             * the record of their past actions remains in the logs for legal history.
             */
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');

            // Action Details
            $table->string('action')->index(); // A short description of the activity (e.g., "Generated Payment Code", "Reset PIN")
            $table->string('resource_type')->nullable(); // e.g., 'App\Models\Merchant'
            $table->unsignedBigInteger('resource_id')->nullable(); // ID of the affected item

            // Detailed payload (Old vs New values)
            $table->json('metadata')->nullable();

            // Network Identity
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // 'created_at' serves as the exact timestamp of the activity
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations (Rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
