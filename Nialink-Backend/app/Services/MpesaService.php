<?php

namespace App\Services;

use App\Models\MpesaTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MpesaService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('mpesa.env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /**
     * Get a valid Daraja OAuth access token.
     *
     * Tokens expire after 60 minutes. We cache for 55 minutes to ensure
     * we never use a token that is about to expire mid-request.
     * Cache miss triggers a fresh token fetch from Safaricom.
     *
     * @throws \Exception if Safaricom returns a non-200 response
     */
    public function getAccessToken(): string
    {
        return Cache::remember('mpesa_access_token', 55 * 60, function () {
            $response = Http::withBasicAuth(
                config('mpesa.consumer_key'),
                config('mpesa.consumer_secret'),
            )->get("{$this->baseUrl}/oauth/v1/generate", [
                'grant_type' => 'client_credentials',
            ]);

            if (! $response->successful()) {
                throw new \Exception(
                    'Failed to obtain M-Pesa access token: ' .
                    $response->body(),
                    502
                );
            }

            return $response->json('access_token');
        });
    }

    // =========================================================================
    // STK PUSH — Consumer tops up their NiaLink wallet
    // =========================================================================

    /**
     * Initiate an STK Push to collect a top-up from a consumer.
     *
     * Flow:
     *   1. NiaLink calls this → Safaricom sends PIN prompt to consumer's phone
     *   2. Consumer enters M-Pesa PIN
     *   3. Safaricom POSTs result to /api/webhooks/mpesa/stk
     *   4. ProcessMpesaTopUpCallback job credits the wallet
     *
     * IMPORTANT: the synchronous response here is NOT a payment confirmation.
     * It only means Safaricom received the request. The actual result
     * arrives asynchronously via the callback URL.
     *
     * Creates a pending MpesaTransaction row immediately.
     * The wallet is only credited when the callback confirms success.
     *
     * @throws \Exception if Daraja API returns an error
     */
    public function initiateTopUp(User $user, float $amount): MpesaTransaction
    {
        $phone     = $this->formatPhone($user->phone_number);
        $timestamp = now()->format('YmdHis');
        $password  = base64_encode(
            config('mpesa.shortcode') .
            config('mpesa.passkey') .
            $timestamp
        );

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", [
                'BusinessShortCode' => config('mpesa.shortcode'),
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => (int) $amount, // Daraja requires integer
                'PartyA'            => $phone,
                'PartyB'            => config('mpesa.shortcode'),
                'PhoneNumber'       => $phone,
                'CallBackURL'       => config('mpesa.stk_callback_url'),
                'AccountReference'  => 'NiaLink',
                'TransactionDesc'   => 'NiaLink wallet top-up',
            ]);

        if (! $response->successful() || $response->json('ResponseCode') !== '0') {
            throw new \Exception(
                'M-Pesa STK Push failed: ' .
                ($response->json('errorMessage') ?? $response->json('ResponseDescription') ?? 'Unknown error'),
                502
            );
        }

        // Create pending row — wallet is NOT credited yet
        return MpesaTransaction::create([
            'id'                  => Str::uuid()->toString(),
            'user_id'             => $user->id,
            'type'                => 'topup',
            'phone_number'        => $phone,
            'amount'              => $amount,
            'currency'            => 'KES',
            'checkout_request_id' => $response->json('CheckoutRequestID'),
            'status'              => 'pending',
        ]);
    }

    // =========================================================================
    // B2C — NiaLink pays out to a consumer or merchant
    // =========================================================================

    /**
     * Initiate a B2C payout from the NiaLink float account to a user.
     *
     * Used for:
     *   - Consumer withdrawal (wallet → M-Pesa)
     *   - Merchant settlement (wallet → M-Pesa)
     *
     * Flow:
     *   1. NiaLink calls this → Safaricom sends money to user's M-Pesa
     *   2. Safaricom POSTs result to /api/webhooks/mpesa/b2c/result
     *   3. ProcessMpesaB2CCallback job handles success or failure
     *   4. On failure: TransactionService::refundWithdrawal() returns funds
     *
     * The wallet is debited BEFORE this is called (in TransactionService).
     * If B2C fails, the callback job triggers a refund.
     *
     * @throws \Exception if Daraja API returns an error
     */
    public function initiateB2C(
        User   $user,
        float  $amount,
        string $type = 'withdrawal',
    ): MpesaTransaction {
        $phone = $this->formatPhone($user->phone_number);

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/mpesa/b2c/v1/paymentrequest", [
                'InitiatorName'      => config('mpesa.initiator_name'),
                'SecurityCredential' => $this->getSecurityCredential(),
                'CommandID'          => 'BusinessPayment',
                'Amount'             => (int) $amount,
                'PartyA'             => config('mpesa.shortcode'),
                'PartyB'             => $phone,
                'Remarks'            => 'NiaLink ' . ucfirst($type),
                'QueueTimeOutURL'    => config('mpesa.b2c_timeout_url'),
                'ResultURL'          => config('mpesa.b2c_result_url'),
                'Occasion'           => '',
            ]);

        if (! $response->successful() || $response->json('ResponseCode') !== '0') {
            throw new \Exception(
                'M-Pesa B2C payout failed: ' .
                ($response->json('errorMessage') ?? $response->json('ResponseDescription') ?? 'Unknown error'),
                502
            );
        }

        return MpesaTransaction::create([
            'id'           => Str::uuid()->toString(),
            'user_id'      => $user->id,
            'type'         => $type,
            'phone_number' => $phone,
            'amount'       => $amount,
            'currency'     => 'KES',
            'status'       => 'pending',
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Format a Kenyan phone number to the 2547XXXXXXXX format Daraja requires.
     *
     * Handles all common input formats:
     *   0712345678   → 254712345678
     *   +254712345678 → 254712345678
     *   254712345678  → 254712345678 (already correct)
     *   0112345678   → 254112345678 (Safaricom 011 numbers)
     */
    public function formatPhone(string $phone): string
    {
        // Strip all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // 07XXXXXXXX or 01XXXXXXXX → 2547XXXXXXXX or 2541XXXXXXXX
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        // +254XXXXXXXXX was stripped to 254XXXXXXXXX above — already correct
        return $phone;
    }

    /**
     * Encrypt the B2C initiator password using Safaricom's public certificate.
     * Daraja requires the password to be RSA-encrypted with their cert.
     *
     * Certificate locations:
     *   Sandbox:    storage/mpesa/sandbox.cer
     *   Production: storage/mpesa/production.cer
     *
     * Download certificates from: https://developer.safaricom.co.ke
     */
    private function getSecurityCredential(): string
    {
        $certPath = config('mpesa.env') === 'production'
            ? storage_path('mpesa/production.cer')
            : storage_path('mpesa/sandbox.cer');

        if (! file_exists($certPath)) {
            throw new \Exception(
                "M-Pesa certificate not found at {$certPath}. " .
                "Download from https://developer.safaricom.co.ke",
                500
            );
        }

        $publicKey = file_get_contents($certPath);

        openssl_public_encrypt(
            config('mpesa.initiator_password'),
            $encrypted,
            $publicKey,
            OPENSSL_PKCS1_PADDING
        );

        return base64_encode($encrypted);
    }
}
