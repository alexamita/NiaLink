<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add email + password columns so admins and merchant admins
     * can authenticate via the web console (email + password)
     * independently of the mobile PIN flow.
     *
     * Both columns are nullable so existing mobile-only users
     * (who have no email address) are not affected.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('name');
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'password']);
        });
    }
};
