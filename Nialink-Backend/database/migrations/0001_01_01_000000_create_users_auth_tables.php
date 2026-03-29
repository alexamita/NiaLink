<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NiaLink User & Authentication Migration
     *
     * Table creation order (foreign key dependency chain):
     *   1. users
     *   2. user_devices
     *   3. user_limit_overrides
     *   4. password_reset_tokens
     *   5. sessions
     */
    public function up(): void
    {
        // =====================================================================
        // 1. USERS
        //    Core identity only.
        //    Two auth paths share this table:
        //      - Consumers      → phone_number + pin  (mobile app)
        //      - Admin/Merchant → email + password    (web dashboard)
        // =====================================================================
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');

            // Consumer: email is null. Admin/Merchant: email is required.
            $table->string('email')->unique()->nullable()
                ->comment('Required for merchant_admin and staff — null for consumers');

            // Admin/Merchant: phone_number is null. Consumer: phone_number is required.
            $table->string('phone_number', 20)->unique()->nullable()
                ->comment('Required for consumers — null for merchant_admin and staff');

            // Consumer auth — bcrypt-hashed 4-6 digit PIN.
            // Always Hash::make() on save. Never store plaintext.
            $table->string('pin')->nullable()
                ->comment('Bcrypt-hashed — consumers only');

            // Admin/Merchant auth — bcrypt-hashed password.
            $table->string('password')->nullable()
                ->comment('Bcrypt-hashed — merchant_admin and staff only');

            // Denormalised cache of the user's primary Spatie role.
            // Used for fast query filtering without a join.
            // Source of truth is still Spatie model_has_roles.
            // Never use this for authorization checks — use $user->hasRole() instead.
            $table->enum('primary_type', ['consumer', 'merchant_admin', 'staff'])
                ->default('consumer')
                ->index()
                ->comment('Denormalised Spatie role cache — not for auth checks');

            // KYC tier drives transaction limits.
            // Limit resolution: user_limit_overrides → kyc tier → system floor.
            // See config/kyc_limits.php for tier values.
            $table->enum('kyc_level', ['tier_1', 'tier_2', 'tier_3'])
                ->default('tier_1')
                ->index()
                ->comment('tier_1=phone verified, tier_2=ID verified, tier_3=full KYC');

            $table->enum('status', ['pending_verification', 'active', 'suspended', 'flagged', 'closed'])
                ->default('pending_verification')
                ->index();

            // Biometric is a client-side UX feature — the PIN is still
            // the server-side secret. Biometric unlocks the stored PIN
            // on-device; it never replaces server-side PIN verification.
            $table->boolean('biometric_enabled')->default(false);

            // Locked to KES for now. Extract to a global config rather than
            // adding columns here if multi-currency support is introduced.
            $table->string('currency', 3)->default('KES');

            $table->timestamp('last_login_at')->nullable();

            // Soft deletes — deactivating a user must never break
            // the transaction history foreign keys that reference them.
            $table->softDeletes();
            $table->timestamps();
        });

        // =====================================================================
        // 2. USER DEVICES
        //    Consumers only. Tracks registered devices with full history.
        //
        //    Two constraints work together:
        //      a) Composite unique (user_id + device_id) — one user cannot
        //         register the same device twice, regardless of status.
        //      b) Partial unique index on (device_id WHERE status = 'active')
        //         — only one user can be active on a device at any moment,
        //         globally. Historical rows are preserved for audit.
        //
        //    One device = one account at a time. If a new user logs in on
        //    a device, the previous user is automatically superseded and
        //    their tokens are deleted by DeviceService::trustDevice().
        // =====================================================================
        Schema::create('user_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Hardware fingerprint of the physical device.
            // No column-level unique — the partial index below handles
            // active-row uniqueness while allowing historical rows to coexist.
            $table->string('device_id')
                ->comment('Hardware fingerprint — one active row per device globally');

            // Firebase Cloud Messaging token for Push-to-Approve flows.
            // Stored per device so notifications reach the correct device.
            $table->string('fcm_token')->nullable()
                ->comment('Firebase token for targeted push notifications');

            // Human-readable label shown in the "active devices" list
            // so users can recognise and revoke specific devices.
            $table->string('device_name')->nullable()
                ->comment('e.g. Wanjiru\'s Tecno Spark 20');

            $table->enum('platform', ['android', 'ios', 'web'])->nullable();
            $table->string('app_version', 20)->nullable();

            // Device lifecycle states:
            //   pending    → registered, OTP not yet confirmed
            //   active     → trusted, can initiate transactions
            //   superseded → displaced by a new user or the same user's new device
            //   revoked    → manually blocked by user or admin
            $table->enum('status', ['pending', 'active', 'superseded', 'revoked'])
                ->default('pending')
                ->index();

            // is_trusted = completed full binding (PIN + OTP confirmed).
            // Untrusted devices can view balances but cannot transact.
            $table->boolean('is_trusted')->default(false);

            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            // Constraint (a): one user cannot register the same device twice.
            $table->unique(['user_id', 'device_id']);
        });

        // Constraint (b): one active row per device globally.
        // Race-condition proof — database rejects concurrent violations.
        DB::statement("
            CREATE UNIQUE INDEX user_devices_one_active_per_device
            ON user_devices (device_id)
            WHERE status = 'active'
        ");

        // =====================================================================
        // 3. USER LIMIT OVERRIDES
        //    Admin-set per-user exceptions to KYC tier defaults.
        //    NULL on any column = fall back to tier default in kyc_limits.php.
        //    Resolution order:
        //      1. Active non-expired override (this table)
        //      2. KYC tier default (config/kyc_limits.php)
        //      3. System floor (LimitService hardcoded minimum)
        // =====================================================================
        Schema::create('user_limit_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // NULL means: use the KYC tier default.
            // P2M = Person-to-Merchant (Nia-Code POS / online checkout)
            $table->decimal('daily_limit_p2m', 15, 2)->nullable()
                ->comment('NULL = use KYC tier default');
            // P2P = Person-to-Person transfer
            $table->decimal('daily_limit_p2p', 15, 2)->nullable()
                ->comment('NULL = use KYC tier default');
            // ATM / cash withdrawal
            $table->decimal('daily_limit_atm', 15, 2)->nullable()
                ->comment('NULL = use KYC tier default');
            $table->unsignedInteger('daily_transaction_count_limit')->nullable()
                ->comment('NULL = use KYC tier default');

            // Required — admin must justify every override for compliance.
            $table->string('reason')
                ->comment('Justification required — stored for audit');

            // Who set the override. Nullable so the row survives
            // if the admin account is later soft-deleted.
            $table->foreignUuid('set_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // NULL = permanent. Set a date for time-bounded overrides.
            $table->timestamp('expires_at')->nullable()
                ->comment('NULL = permanent. Set for time-bounded exceptions.');

            $table->timestamps();

            // One active override per user. To update: delete and recreate
            // so created_at always reflects when the override was applied.
            $table->unique('user_id');
        });

        // =====================================================================
        // 4. PASSWORD RESET TOKENS
        //    One table handles three flows:
        //      - phone_otp      → consumer registration & phone verification
        //      - pin_reset      → consumer PIN reset via SMS OTP
        //      - password_reset → merchant/admin password reset via email link
        // =====================================================================
        Schema::create('password_reset_tokens', function (Blueprint $table) {

            // Primary key = one active token per identifier at a time.
            // OtpService::generate() uses upsert() — spamming resend
            // replaces the row rather than creating duplicates.
            $table->string('identifier')->primary()
                ->comment('phone_number for consumers, email for admin/merchant');

            // Always Hash::make() on save, Hash::check() on verify.
            // Compromised DB yields hashes, not live OTPs.
            $table->string('token')
                ->comment('Bcrypt-hashed — never store plaintext');

            // Controls TTL, token format, and verification handler:
            //   phone_otp / pin_reset  → 6-digit code, 5 min TTL
            //   password_reset         → 64-char string, 60 min TTL
            $table->enum('type', ['phone_otp', 'pin_reset', 'password_reset'])
                ->default('phone_otp');

            // Brute-force guard — checked before Hash::check() to avoid
            // bcrypt cost on locked-out tokens. Max 5 attempts.
            $table->unsignedTinyInteger('attempts')->default(0)
                ->comment('Row invalidated after 5 failed attempts');

            // Hard server-side expiry — never rely on the client.
            $table->timestamp('expires_at')
                ->comment('phone_otp/pin_reset: 5 min | password_reset: 60 min');

            $table->timestamp('created_at')->nullable();
        });

        // =====================================================================
        // 5. SESSIONS
        //    Standard Laravel HTTP session tracking.
        //    user_id is uuid to match the users primary key.
        //    Used by merchant/admin web dashboard only.
        //    Mobile consumers use Sanctum tokens, not sessions.
        // =====================================================================
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    // Drop in strict reverse order — children before parents.
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_limit_overrides');
        DB::statement('DROP INDEX IF EXISTS user_devices_one_active_per_device');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('users');
    }
};
