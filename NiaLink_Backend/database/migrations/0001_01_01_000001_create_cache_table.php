<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * Cache & Atomic Locks Migration
 * * This migration provides high-speed data storage and concurrency control.
 * In NiaLink, this is used to store temporary data (like active 6-digit codes)
 * and to prevent "Double Spending" using atomic locks.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * * Creates the infrastructure for database-backed caching and
     * prevention of race conditions.
     */
    public function up(): void
    {
        // 1. Cache Table: Stores temporary, frequently accessed data for speed.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary(); // The unique name of the cached item (e.g., 'user_1_code')
            $table->mediumText('value'); // The actual data being stored
            $table->integer('expiration')->index(); // When the data should be automatically ignored/cleared
        });

        // 2. Cache Locks Table: Manages "Atomic Locks" to prevent race conditions.
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary(); // The resource being locked (e.g., 'processing_payment_user_5')
            $table->string('owner'); // The process or request ID that currently owns the lock
            $table->integer('expiration')->index(); // Safety timeout to release the lock if a process crashes
        });
    }

    /**
     * Reverse the migrations (Rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
