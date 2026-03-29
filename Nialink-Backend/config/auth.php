<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | The default guard is 'web' for the admin/merchant dashboard.
    | Mobile consumers authenticate via Sanctum tokens — no default
    | guard change needed since Sanctum handles token resolution
    | automatically via auth:sanctum middleware.
    |
    */
    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Three guards are defined:
    |
    |   web      → Session-based. Used by the merchant/admin web dashboard.
    |
    |   consumer → Sanctum token-based. Used by the mobile app.
    |              Scoped to consumers so middleware can distinguish
    |              persona without checking primary_type on every request.
    |
    |   admin    → Sanctum token-based. Used by merchant_admin and staff
    |              accessing the web dashboard via API.
    |
    | All three guards use the same 'users' provider and the same User model.
    | Roles and primary_type differentiate behaviour — not separate tables.
    |
    */
    'guards' => [

        // Standard web session guard — merchant/admin dashboard
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        // Mobile consumer guard — Sanctum token-based
        'consumer' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],

        // Admin/Merchant guard — Sanctum token or session
        'admin' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All three guards share the same User model.
    | Consumers, merchants, and admins are all rows in the users table.
    | Their roles (via Spatie) and primary_type column differentiate them.
    |
    */
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => env('AUTH_MODEL', User::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | NiaLink handles all resets through OtpService, not Laravel's built-in
    | password reset system. This configuration is kept for compatibility
    | with any Laravel internals that reference it, but is not used directly.
    |
    | Consumer PIN resets → OtpService (type: pin_reset, SMS OTP)
    | Admin password resets → OtpService (type: password_reset, email link)
    |
    */
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Number of seconds before a password confirmation window expires.
    | Default is 3 hours (10800 seconds).
    |
    */
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
