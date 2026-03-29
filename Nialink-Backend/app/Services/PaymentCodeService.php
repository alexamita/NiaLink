<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentCodeService
{
    // How long a Nia-Code is valid in seconds.
    // Server-side enforcement — the client timer is display only.
    private const TTL_SECONDS = 120;

    // Redis key prefixes — centralised here so a typo in one place
    // doesn't silently break the lookup in another.
    private const CODE_KEY   = 'nialink_auth_';      // code → transaction_id
    private const USER_KEY   = 'user_active_code_';  // user_id → active code
    private const LOCK_KEY   = 'processing_nialink_'; // distributed lock per code

    public function __construct(
        protected AuditService $auditService,
        protected LimitService $limitService,
    ) {}

    /**
     * Generate a new 6-digit Nia-Code for a consumer.
     *
     * What this does:
     *   1. Cancels any existing active code for this user
     *   2. Generates a globally unique 6-digit code
     *   3. Creates a pending Transaction skeleton in the DB
     *   4. Stores code → transaction_id in Redis (TTL 120s)
     *   5. Stores user_id → code in Redis (TTL 120s)
     *
     * The 6-digit code NEVER touches the database.
     * payment_code_reference stores the Redis key name for audit only.
     *
     * @throws \Exception if amount exceeds P2M limit
     */
    public function generate(User $user, ?float $amount = null): array
    {
        // Pre-validate amount against limit before touching Redis or DB
        if ($amount !== null) {
            $check = $this->limitService->check($user, 'p2m', $amount);
            if (! $check['allowed']) {
                throw new \Exception($check['reason'], 422);
            }
        }

        // Cancel any existing active code for this user
        $this->cancelExistingCode($user);

        // Generate a code that is not currently active in Redis
        $code = $this->uniqueCode();

        return DB::transaction(function () use ($user, $code, $amount) {

            // Create a pending transaction skeleton.
            // Amount may be null (open-amount code — merchant enters at POS).
            // Amount is set when the merchant claims the code.
            $transaction = Transaction::create([
                'user_id'                => $user->id,
                'reference'              => $this->reference(),
                'payment_code_reference' => self::CODE_KEY . $code,
                'amount'                 => $amount ?? 0,
                'fee'                    => 0,
                'currency'               => 'KES',
                'type'                   => 'p2m',
                'status'                 => 'pending',
            ]);

            // Store in Redis — two keys for two lookup directions
            Cache::put(self::CODE_KEY . $code, $transaction->id, self::TTL_SECONDS);
            Cache::put(self::USER_KEY . $user->id, $code, self::TTL_SECONDS);

            $this->auditService->logSecurity(
                'payment_code.generated',
                ['expires_in' => self::TTL_SECONDS, 'has_amount' => $amount !== null],
                $user->id,
            );

            return [
                'code'       => $code,
                'expires_in' => self::TTL_SECONDS,
                'reference'  => $transaction->reference,
                'amount'     => $amount,
            ];
        });
    }

    /**
     * Validate a Nia-Code submitted by a merchant terminal at POS.
     *
     * What this does:
     *   1. Acquires a distributed Redis lock on this code
     *   2. Looks up the transaction_id from Redis
     *   3. Verifies the transaction exists and is still pending
     *   4. Verifies the terminal is operational
     *   5. Updates the transaction to 'processing' with merchant + amount
     *   6. Returns the transaction for TransactionService to settle
     *
     * The code is NOT removed from Redis here — that happens in
     * TransactionService after the ledger entries are written.
     * This preserves the ability to detect duplicate claim attempts.
     *
     * @throws \Exception if code invalid, expired, terminal not operational,
     *                    or merchant not active
     */
    public function validate(
        string   $code,
        Terminal $terminal,
        float    $amount,
    ): Transaction {
        $lock = Cache::lock(self::LOCK_KEY . $code, 10);

        $transaction = $lock->get(function () use ($code, $terminal, $amount) {

            // Look up the transaction ID from Redis
            $transactionId = Cache::get(self::CODE_KEY . $code);

            if (! $transactionId) {
                $this->auditService->logSecurity('payment_code.invalid_attempt', [
                    'code'        => $code,
                    'terminal'    => $terminal->terminal_code,
                    'merchant_id' => $terminal->merchant_id,
                ]);
                throw new \Exception('Invalid or expired payment code.', 404);
            }

            // Fetch the pending transaction
            $transaction = Transaction::where('id', $transactionId)
                ->where('status', 'pending')
                ->first();

            if (! $transaction) {
                throw new \Exception('Payment code has already been used or has expired.', 422);
            }

            // Check the terminal is operational
            if (! $terminal->isOperational()) {
                throw new \Exception('This terminal is not currently operational.', 403);
            }

            $merchant = $terminal->merchant;

            // If the code was generated with a fixed amount, enforce it
            if ((float) $transaction->amount > 0 && (float) $transaction->amount !== $amount) {
                throw new \Exception(
                    'Amount does not match the payment code. ' .
                    'Expected: KES ' . number_format((float) $transaction->amount, 2),
                    422
                );
            }

            // Move transaction to 'processing' — attaches merchant, terminal, amount
            $transaction->update([
                'merchant_id' => $merchant->id,
                'terminal_id' => $terminal->id,
                'amount'      => $amount,
                'status'      => 'processing',
            ]);

            // Update terminal activity timestamp
            $terminal->touchActivity();

            $this->auditService->log(
                'payment_code.validated',
                $transaction,
                [
                    'terminal'    => $terminal->terminal_code,
                    'merchant'    => $merchant->business_name,
                    'amount'      => $amount,
                ],
            );

            return $transaction->fresh();
        });

        if (! $transaction) {
            throw new \Exception('Concurrent processing detected. Please try again.', 409);
        }

        return $transaction;
    }

    /**
     * Cancel the active code for a user.
     * Called when:
     *   - User generates a new code (replaces the old one)
     *   - User explicitly cancels from the app
     *   - Logout clears any pending code
     */
    public function cancelExistingCode(User $user): void
    {
        $existingCode = Cache::get(self::USER_KEY . $user->id);

        if (! $existingCode) {
            return;
        }

        // Remove from Redis
        Cache::forget(self::CODE_KEY . $existingCode);
        Cache::forget(self::USER_KEY . $user->id);

        // Mark the pending transaction as expired
        Transaction::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('type', 'p2m')
            ->latest()
            ->first()
            ?->update(['status' => 'expired']);
    }

    /**
     * Clean up Redis keys after a payment is completed or failed.
     * Called by TransactionService after settlement.
     */
    public function consume(string $code, string $userId): void
    {
        Cache::forget(self::CODE_KEY . $code);
        Cache::forget(self::USER_KEY . $userId);
    }

    /**
     * Expire all stale pending transactions whose 120-second window has passed.
     * Called by a scheduled job every 2 minutes.
     * Returns the number of transactions expired.
     */
    public function expireStale(): int
    {
        return Transaction::where('status', 'pending')
            ->where('type', 'p2m')
            ->where('created_at', '<=', now()->subSeconds(self::TTL_SECONDS))
            ->update(['status' => 'expired']);
    }

    /**
     * Generate a unique 6-digit code that is not currently active in Redis.
     * Retries up to 10 times before giving up — collision probability is
     * extremely low (1 in 900,000 per attempt at modest scale).
     *
     * @throws \Exception if unable to generate unique code after 10 attempts
     */
    private function uniqueCode(): string
    {
        $attempts = 0;

        do {
            $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $attempts++;
        } while (Cache::has(self::CODE_KEY . $code) && $attempts < 10);

        if ($attempts >= 10 && Cache::has(self::CODE_KEY . $code)) {
            throw new \Exception('Unable to generate unique payment code. Please try again.', 500);
        }

        return $code;
    }

    /**
     * Generate a unique transaction reference.
     * Format: NL-YYYYMMDD-XXXXXXXX
     */
    private function reference(): string
    {
        return 'NL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}
