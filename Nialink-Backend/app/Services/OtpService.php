<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OtpService
{
    // How long each token type stays valid (minutes)
    private const TTL = [
        'phone_otp'      => 5,
        'pin_reset'      => 5,
        'password_reset' => 60,
    ];

    // Max failed attempts before the token is invalidated
    private const MAX_ATTEMPTS = 5;

    /**
     * Generate and store a new token for the given identifier.
     *
     * Uses upsert() so calling this twice for the same identifier
     * replaces the old token — spamming "resend" is safe.
     *
     * Returns the PLAINTEXT token — send this to the user via SMS
     * or email, then discard it. Never log it.
     */
    public function generate(string $identifier, string $type): string
    {
        // Password resets use a long random string (sent as a URL token).
        // OTPs use a 6-digit number (sent via SMS).
        $plaintext = $type === 'password_reset'
            ? Str::random(64)
            : (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->upsert(
            [
                'identifier' => $identifier,
                'token'      => Hash::make($plaintext),
                'type'       => $type,
                'attempts'   => 0,
                'expires_at' => now()->addMinutes(self::TTL[$type]),
                'created_at' => now(),
            ],
            uniqueBy: ['identifier'],
            update: ['token', 'type', 'attempts', 'expires_at', 'created_at']
        );

        return $plaintext;
    }

    /**
     * Verify a token submitted by the user.
     *
     * Returns true and deletes the row on success.
     * Returns false and increments attempts on failure.
     * Deletes the row and returns false if expired or max attempts exceeded.
     */
    public function verify(string $identifier, string $plaintext): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('identifier', $identifier)
            ->first();

        // No token exists for this identifier
        if (! $record) {
            return false;
        }

        // Token has expired — clean up and reject
        if (now()->isAfter($record->expires_at)) {
            $this->invalidate($identifier);
            return false;
        }

        // Too many failed attempts — lock out and reject
        if ($record->attempts >= self::MAX_ATTEMPTS) {
            $this->invalidate($identifier);
            return false;
        }

        // Wrong token — increment attempt counter and reject
        if (! Hash::check($plaintext, $record->token)) {
            DB::table('password_reset_tokens')
                ->where('identifier', $identifier)
                ->increment('attempts');
            return false;
        }

        // Valid — consume the token immediately so it cannot be reused
        $this->invalidate($identifier);
        return true;
    }

    /**
     * Delete the token row.
     * Called after successful verification or on max attempts exceeded.
     */
    public function invalidate(string $identifier): void
    {
        DB::table('password_reset_tokens')
            ->where('identifier', $identifier)
            ->delete();
    }
}
