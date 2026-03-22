<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * Queue Infrastructure Migration
 * * This migration sets up the "Background Processing" system.
 * For NiaLink, this is essential for tasks that shouldn't make the user wait,
 * such as sending OTP SMS messages, generating monthly statements,
 * or syncing transaction data with external accounting tools.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Jobs Table: The "Waiting Room" for background tasks.
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index(); // The name of the pipe (e.g., 'sms', 'default')
            $table->longText('payload'); // Encrypted data needed to perform the task
            $table->unsignedTinyInteger('attempts'); // Tracks retries if the task fails (e.g., SMS provider is down)
            $table->unsignedInteger('reserved_at')->nullable(); // Timestamp when a worker starts the job
            $table->unsignedInteger('available_at'); // When the job is ready to be picked up
            $table->unsignedInteger('created_at');
        });

        // 2. Job Batches: Allows tracking multiple related jobs as one unit.
        // Useful for NiaLink if you're sending a bulk notification to all merchants.
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        // 3. Failed Jobs: The "Hospital" for tasks that couldn't be completed.
        // If an SMS fails to send after all attempts, it ends up here for manual review.
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection'); // Which queue driver was used (database, redis, etc.)
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception'); // The error message/stack trace of why it failed
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
