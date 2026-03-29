<?php

use App\Http\Controllers\Api\Admin\ManagementController;
use App\Http\Controllers\Api\MerchantPaymentController;
use App\Http\Controllers\Api\NiaCodeController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\ConsumerAuthController;
use App\Http\Controllers\Payment\TransactionController;
use App\Http\Controllers\Wallet\WalletController;
use App\Http\Controllers\Webhook\MpesaCallbackController;
use Illuminate\Support\Facades\Route;

/*
|---------------------
| NiaLink API Routes
|---------------------
|
| Route groups in order:
|
|   1. Safaricom webhooks     → Public, no auth, no throttle
|   2. Consumer auth          → Public, throttled
|   3. POS terminal           → Public, terminal-secret auth, throttled
|   4. Admin auth             → Public, throttled
|   5. Consumer protected     → Sanctum + check.status + active.device
|   6. Merchant admin         → Sanctum + check.status + role:merchant_admin
|   7. Platform admin         → Sanctum + check.status + role:admin|super_admin
|
*/

/*
|------------------------------------
| 1. SAFARICOM WEBHOOK CALLBACKS
|------------------------------------
|
| Called directly by Safaricom — no Bearer token, no device ID.
| Must return HTTP 200 within 5 seconds or Safaricom retries,
| which risks duplicate wallet credits.
|
| Security model: requests are validated inside each controller method
| by matching CheckoutRequestID to a known pending MpesaTransaction row.
| No auth middleware — Safaricom cannot authenticate as a NiaLink user.
|
*/
Route::prefix('webhooks/mpesa')
    ->name('webhooks.mpesa.')
    ->group(function () {
        Route::post('stk',         [MpesaCallbackController::class, 'stkCallback'])->name('stk');
        Route::post('b2c/result',  [MpesaCallbackController::class, 'b2cResult'])->name('b2c.result');
        Route::post('b2c/timeout', [MpesaCallbackController::class, 'b2cTimeout'])->name('b2c.timeout');
    });

/*
|-------------------------------
| 2. CONSUMER AUTHENTICATION
|-------------------------------
| Mobile app registration and login flow.
|
| Throttle limits (per IP per minute):
|   register / login → 6 attempts  (brute-force protection)
|   OTP endpoints    → 3 attempts  (prevents OTP spam and enumeration)
|
| Flow:
|   POST register   → creates account (pending_verification) + sends OTP
|   POST verify-otp → confirms OTP, activates account, trusts device
|   POST login      → phone + PIN + device_id → returns Bearer token
|   POST resend-otp → re-sends OTP to phone (rate limited)
|
*/
Route::prefix('consumer/auth')
    ->name('consumer.auth.')
    ->group(function () {

        Route::middleware('throttle:6,1')->group(function () {
            Route::post('register', [ConsumerAuthController::class, 'register'])->name('register');
            Route::post('login',    [ConsumerAuthController::class, 'login'])->name('login');
        });

        Route::middleware('throttle:3,1')->group(function () {
            Route::post('verify-otp', [ConsumerAuthController::class, 'verifyOtp'])->name('verify-otp');
            Route::post('resend-otp', [ConsumerAuthController::class, 'sendOtp'])->name('resend-otp');
        });
    });

/*
|-------------------------------------------------
| 3. POS TERMINAL — MERCHANT PAYMENT ENDPOINTS
|-------------------------------------------------
|
| Called by pre-configured merchant POS hardware — not the consumer app.
|
| Authentication: terminal_code + terminal_secret (hashed in DB).
| No Sanctum token — POS devices cannot maintain browser sessions.
| terminal_secret is verified inside MerchantPaymentController before
| any payment logic executes.
|
| Throttle: 60 requests per minute per IP.
| Protects against burst abuse without blocking busy checkout counters.
|
| Flow:
|   POST payment → consumer shows code, cashier enters code + amount
|                → validates code in Redis, settles atomically (<100ms)
|   GET  payment/{reference} → terminal polls status if connection dropped mid-payment
|
*/
Route::prefix('pos')
    ->name('pos.')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::post('payment', [MerchantPaymentController::class, 'process'])->name('payment.process');
        Route::get('payment/{reference}', [MerchantPaymentController::class, 'status'])->name('payment.status');
    });

/*
|------------------------------------------------
| 4. ADMIN / MERCHANT DASHBOARD AUTHENTICATION
|------------------------------------------------
|
| Web dashboard login for NiaLink admins and merchant admins.
| Uses email + password — NOT phone + PIN.
| Returns a Sanctum Bearer token valid for the dashboard session.
|
*/
Route::prefix('admin/auth')
    ->name('admin.auth.')
    ->middleware('throttle:6,1')
    ->group(function () {
        Route::post('login', [AdminAuthController::class, 'login'])->name('login');
    });

/*
|---------------------------------
| 5. CONSUMER PROTECTED ROUTES
|---------------------------------
|
| All routes in this group require:
|   auth:sanctum  → valid Bearer token in Authorization header
|   check.status  → account must be 'active' (not suspended/flagged/closed)
|   active.device → X-Device-ID header must match a trusted device record
|
| Granular permissions are enforced per sub-group via Spatie.
| Permissions are assigned to the 'consumer' role in RolesAndPermissionsSeeder.
|
*/
Route::middleware(['auth:sanctum', 'check.status', 'active.device'])
    ->prefix('consumer')
    ->name('consumer.')
    ->group(function () {

        // ── Session ──
        Route::post('auth/logout', [ConsumerAuthController::class, 'logout'])->name('auth.logout');

        // ── Wallet ──
        // Balance, limits, and remaining daily headroom
        Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
        // All transaction types — paginated, 20 per page
        Route::get('wallet/history', [WalletController::class, 'history'])->name('wallet.history');
        // M-Pesa top-up history only
        Route::get('wallet/topups', [WalletController::class, 'topUpHistory'])->name('wallet.topups');
        // Initiates STK Push — returns 202, wallet credited asynchronously via callback
        Route::post('wallet/topup', [WalletController::class, 'topUp'])->name('wallet.topup');
        // Debits wallet immediately — B2C payout arrives asynchronously
        Route::post('wallet/withdraw', [WalletController::class, 'withdraw'])->name('wallet.withdraw');

        // ── Payment Codes (Nia-Codes) ──
        // The core product: 6-digit ephemeral codes for POS payments.
        // Code lives in Redis only (120s TTL) — never stored in the database.
        Route::middleware('permission:generate-payment-code')
            ->name('payment-codes.')
            ->group(function () {
                // Generates code, cancels any existing active code for this user
                Route::post('payment-codes', [NiaCodeController::class, 'store'])->name('store');
                // User closes payment screen without completing
                Route::delete('payment-codes', [NiaCodeController::class, 'cancel'])->name('cancel');
            });

        // ── Transactions ──
        Route::middleware('permission:view-own-transactions')
            ->name('transactions.')
            ->group(function () {
                // All transactions where user is initiator, sender, or receiver
                Route::get('transactions', [TransactionController::class, 'index'])->name('index');
                // Full detail including ledger entries — by reference e.g. NL-20260329-ABCD1234
                Route::get('transactions/{reference}', [TransactionController::class, 'show'])->name('show');
            });

        // ── P2P Transfers ──
        // Send money directly to another consumer by phone number — no fee
        Route::middleware('permission:initiate-transfer')
            ->group(function () {
                Route::post('transfers', [TransactionController::class, 'transfer'])->name('transfers.store');
            });
    });

/*
|---------------------------------------
| 6. MERCHANT ADMIN PROTECTED ROUTES
|---------------------------------------
|
| Business owners managing their own merchant account.
| Can only see their own data — no access to other merchants.
|
| Middleware:
|   auth:sanctum → valid Bearer token
|   check.status → account must be active
|   role:merchant_admin → Spatie role check (no device binding)
|
*/
Route::middleware(['auth:sanctum', 'check.status', 'role:merchant_admin'])
    ->prefix('admin/merchant')
    ->name('merchant.')
    ->group(function () {

        // Business profile, verification status, and terminal list
        Route::get('profile', [ManagementController::class, 'merchantProfile'])->name('profile');
        // All payments received via Nia-Code — paginated
        Route::get('transactions', [ManagementController::class, 'merchantTransactions'])->name('transactions');
        // Current wallet balance and lifetime totals
        Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
        // Withdraw accumulated merchant balance to M-Pesa
        Route::post('wallet/withdraw', [WalletController::class, 'withdraw'])->name('wallet.withdraw');
    });

/*
|--------------------------------------
| 7. PLATFORM ADMIN PROTECTED ROUTES
|--------------------------------------
|
| NiaLink internal staff only — full system access.
| super_admin inherits all admin permissions.
|
| Middleware:
|   auth:sanctum → valid Bearer token
|   check.status → account must be active
|   role:admin|super_admin → Spatie role check
|
*/
Route::middleware(['auth:sanctum', 'check.status', 'role:admin|super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Session
        Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');

        // ── Dashboard ──
        // Total float, revenue, 24h volume, pending merchants, open AML flags
        Route::get('stats', [ManagementController::class, 'dashboard'])->name('stats');

        // ── User Management ──
        // Filters: ?status=active|suspended&kyc_level=tier_1|tier_2|tier_3
        Route::get('users', [ManagementController::class, 'listUsers'])->name('users.index');
        Route::post('users/{id}/suspend', [ManagementController::class, 'suspendUser'])->name('users.suspend');

        // ── Wallet & Float Monitor ──
        // Total system float — must always equal trust account balance (CBK)
        Route::get('wallets', [ManagementController::class, 'listWallets'])->name('wallets.index');
        Route::post('wallets/{id}/freeze', [ManagementController::class, 'freezeWallet'])->name('wallets.freeze');
        Route::post('wallets/{id}/unfreeze', [ManagementController::class, 'unfreezeWallet'])->name('wallets.unfreeze');

        // ── Merchant KYC Management ──
        // Filters: ?status=pending|active|suspended|rejected
        Route::get('merchants', [ManagementController::class, 'listMerchants'])->name('merchants.index');
        // Approve → sets status=active, provisions wallet
        Route::post('merchants/{id}/approve', [ManagementController::class, 'approveMerchant'])->name('merchants.approve');
        // Suspend → all merchant terminals go offline immediately
        Route::post('merchants/{id}/suspend', [ManagementController::class, 'suspendMerchant'])->name('merchants.suspend');
        // Reject → sets rejection_reason shown to merchant
        Route::post('merchants/{id}/reject', [ManagementController::class, 'rejectMerchant'])->name('merchants.reject');

        // ── Terminal Management ──
        // Lock targets a single till without affecting the merchant or other terminals
        Route::get('terminals', [ManagementController::class, 'listTerminals'])->name('terminals.index');
        Route::post('terminals/{id}/lock', [ManagementController::class, 'lockTerminal'])->name('terminals.lock');
        Route::post('terminals/{id}/unlock', [ManagementController::class, 'unlockTerminal'])->name('terminals.unlock');

        // ── Transaction Ledger ──
        // Filters: ?status=completed|failed|pending&type=p2m|p2p|topup|withdrawal
        // &from=YYYY-MM-DD&to=YYYY-MM-DD
        Route::get('transactions', [ManagementController::class, 'listTransactions'])->name('transactions.index');

        // ── CBK Float & Reconciliation ──
        // Float transactions — every real KES movement through the trust account
        Route::get('float', [ManagementController::class, 'listFloatTransactions'])->name('float.index');
        // Daily snapshots — trust account balance vs sum of all wallets
        Route::get('reconciliations', [ManagementController::class, 'listReconciliations'])->name('reconciliations.index');

        // ── AML Compliance ──
        // Filters: ?status=open|under_review|cleared|reported&severity=critical|high|medium|low
        Route::get('aml-flags', [ManagementController::class, 'listAmlFlags'])->name('aml-flags.index');
        // Body: { action: claim|clear|report, review_notes, frc_reference (report only) }
        Route::post('aml-flags/{id}/review', [ManagementController::class, 'reviewAmlFlag'])->name('aml-flags.review');

        // ── Audit Trail ──
        // Immutable event log — CBK requires 5-year retention
        // Filters: ?action=merchant.approved&user_id=uuid
        Route::get('audit', [ManagementController::class, 'listAuditLogs'])->name('audit.index');
    });
