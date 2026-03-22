<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\NiaCodeController;
use App\Http\Controllers\Api\MerchantPaymentController;
use App\Http\Controllers\Api\AppApprovalController;
use App\Http\Controllers\Api\TerminalController;
use App\Http\Controllers\Api\Admin\ManagementController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------
| 1. PUBLIC IDENTITY ROUTES (Auth & Security)
|---------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware('throttle:3,1')->group(function () {
        Route::post('send-otp', [AuthController::class, 'sendOtp']);
        Route::post('verify-account', [AuthController::class, 'verifyAccount']);
        Route::post('reset-pin', [AuthController::class, 'resetPin']);
    });
});

/*
|---------------------------------------------------
| 2. USER PROTECTED ROUTES (The NiaLink Mobile App)
|---------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Account Settings
    Route::prefix('user')->group(function () {
        Route::post('change-pin', [AuthController::class, 'changePin']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('balance', [AuthController::class, 'getBalance']); // Added for UI
    });

    // Nia-Code Operations (Client side)
    Route::prefix('nialink')->group(function () {
        // Step 1: User generates the 6-digit code
        Route::post('generate', [NiaCodeController::class, 'store']);

        // Step 3: User approves the "Push Notification" request with their PIN
        Route::post('approve', [AppApprovalController::class, 'approve']);
    });
});

/*
|-------------------------------------------------
| 3. MERCHANT / POS ROUTES (The "Pull" Handshake)
|-------------------------------------------------
*/
Route::prefix('pos')->group(function () {
    // Step 2: Merchant submits the 6-digit code and amount
    Route::post('payment/claim', [MerchantPaymentController::class, 'process']);

    // Step 4: Terminal polls to see if the user has approved yet
    Route::get('payment/status/{reference}', [TerminalController::class, 'checkStatus']);
});

/*
|----------------------------------
| 4. ADMIN MANAGEMENT (Backoffice)
|----------------------------------
*/
Route::middleware(['auth:sanctum', 'can:admin-access'])->prefix('admin')->group(function () {
    Route::get('stats', [ManagementController::class, 'dashboard']);
    Route::post('merchants/{id}/approve', [ManagementController::class, 'approveMerchant']);
});
