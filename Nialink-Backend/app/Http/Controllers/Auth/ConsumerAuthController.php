<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumerLoginRequest;
use App\Http\Requests\Auth\ConsumerRegisterRequest;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\OtpService;
use App\Services\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsumerAuthController extends Controller
{
    public function __construct(
        protected UserAuthService $authService,
        protected OtpService      $otpService,
        protected DeviceService   $deviceService,
    ) {}

    /**
     * Step 1 of 2 — Register a new consumer.
     *
     * Creates the user + pending device row together.
     * Sends a 6-digit OTP to the phone number.
     * Returns a warning flag if another account will be displaced.
     */
    public function register(ConsumerRegisterRequest $request): JsonResponse
    {
        // Warn if another user is currently active on this device.
        // The client should show a confirmation dialog before proceeding.
        $displacingAnotherUser = $this->deviceService
            ->isOwnedByAnotherUser($request->device_id);

        $user = $this->authService->registerConsumer($request->validated());

        // Generate OTP — covers both phone verification AND device binding
        $otp = $this->otpService->generate($user->phone_number, 'phone_otp');

        return response()->json([
            'message'=> 'OTP sent to ' . $user->phone_number,
            'displacing_other_user' => $displacingAnotherUser,
            'otp_debug'=>$otp, // TODO: remove this in production
        ], 201);
    }

    /**
     * Step 2 of 2 — Verify OTP.
     *
     * Activates the user account AND trusts the device in one call.
     * Issues a Sanctum token — consumer is fully onboarded and ready to transact.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number' => ['required', 'string'],
            'otp'          => ['required', 'string', 'size:6'],
            'device_id'    => ['required', 'string'],
        ]);

        $user = User::where('phone_number', $request->phone_number)
            ->where('status', 'pending_verification')
            ->firstOrFail();

        try {
            $result = $this->authService->verifyRegistrationOtp(
                $user,
                $request->otp,
                $request->device_id
            );
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return response()->json($result, 200);
    }

    /**
     * Login an existing consumer.
     * Requires phone_number + PIN + device_id.
     * Device must already be active and trusted.
     */
    public function login(ConsumerLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->loginConsumer(
                $request->phone_number,
                $request->pin,
                $request->device_id
            );
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return response()->json($result, 200);
    }

    /**
     * Resend OTP to a user awaiting phone verification.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number' => ['required', 'string'],
        ]);

        $user = User::where('phone_number', $request->phone_number)
            ->where('status', 'pending_verification')
            ->firstOrFail();

        $this->otpService->generate($user->phone_number, 'phone_otp');

        return response()->json([
            'message' => 'OTP resent to ' . $user->phone_number,
        ], 200);
    }

    /**
     * Logout — revokes the current Sanctum token and marks the device inactive.
     */
    public function logout(Request $request): JsonResponse
    {
        $deviceId = $request->header('X-Device-ID');

        if ($deviceId) {
            $this->deviceService->revokeDevice($request->user(), $deviceId);
        } else {
            // Fallback — just delete the token if no device header
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Logged out successfully.'], 200);
    }
}
