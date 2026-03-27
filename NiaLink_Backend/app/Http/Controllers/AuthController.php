<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;


/**
 * AuthController
 * * This class serves as the security gateway for the NiaLink ecosystem.
 * It manages the entire identity lifecycle of a user—from initial onboarding
 * and phone number verification to security recovery via OTP-based PIN resets.
 * * Core Features:
 * - Atomic User Registration
 * - Secure 6-digit OTP Generation and Hashing
 * - Status-based Account Activation (Pending -> Active)
 * - Multi-step PIN Recovery Logic
 */
class AuthController extends Controller
{
    /**
     * Step 1: REGISTER a new account (Public Flow).
     * * Creates a dormant user record with a 'pending_verification' status.
     * This ensures that while the user exists in the system, they cannot
     * perform any financial actions until Step 3 is completed.
     * * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        // 1. Validation: Phone numbers must be unique in Kenya (NiaLink)
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'phone_number' => 'required|string|unique:users,phone_number',
            'pin'          => 'required|string|size:4|confirmed', // Standard 4-digit APP PIN
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 2. Creation: Initialize the user with a 0 balance.
        $user = User::create([
            'name'         => $request->name,
            'phone_number' => $request->phone_number,
            'pin'          => $request->pin, // Casts attribute in Model handles hashing
            'balance'      => 0.00,
            'status'       => 'pending_verification', // User cannot make payments yet
        ]);

        // Auto-trigger the OTP send logic for the user
        return $this->sendOtp($request);
    }


    /**
     * Step 2: SEND/RESEND OTP to the user's phone (Public Flow).
     * * Generates a random 6-digit code, hashes it for database security,
     * and stores/updates it in the 'password_reset_tokens' table.
     * * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $request->validate(['phone_number' => 'required|exists:users,phone_number']);

        $otp = rand(100000, 999999);

        // Store hashed OTP in the reset table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['phone_number' => $request->phone_number],
            [
                'token' => Hash::make($otp),
                'created_at' => now()
            ]
        );

        /**
         * PRODUCTION LOGIC: To trigger SMS API here (e.g., Africa's Talking)
         */
        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent to ' . $request->phone_number,
            'otp_debug' => $otp // REMOVE IN PRODUCTION: To test the "Verify Account" logic without needing a real SIM card.
        ]);
    }


    /**
     * Step 3: VERIFY Phone and ACTIVATE Account (Public Flow).
     * * Validates the user-provided OTP against the hashed token.
     * Upon success, it flips the user status to 'active', enabling
     * full NiaLink service functionality.
     * * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyAccount(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|exists:users,phone_number',
            'otp' => 'required|digits:6',
        ]);

        $verifyData = DB::table('password_reset_tokens')
            ->where('phone_number', $request->phone_number)
            ->first();

        // Check if OTP exists and is not older than 10 minutes
        if (!$verifyData || Hash::check($request->otp, $verifyData->token) === false) {
            return response()->json(['error' => 'Invalid verification code'], 422);
        }

        if (Carbon::parse($verifyData->created_at)->addMinutes(10)->isPast()) {
            return response()->json(['error' => 'Code has expired'], 422);
        }

        // Activate the user
        User::where('phone_number', $request->phone_number)->update([
            'status' => 'active'
        ]);

        // Clean up token
        DB::table('password_reset_tokens')->where('phone_number', $request->phone_number)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Account activated successfully. You can now generate NiaLink codes.'
        ]);
    }


    /**
     * ADMIN LOGIN — Web Console (Public Flow).
     *
     * Authenticates admins and merchant admins via email + password.
     * Mobile PIN credentials are not accepted here.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if (!in_array($user->user_role, ['admin', 'merchant_admin'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Access denied. This portal is for administrators only.',
            ], 403);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your account is suspended. Contact support.',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('admin_console')->plainTextToken;

        return response()->json([
            'status'       => 'success',
            'message'      => 'Login successful.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'user_role' => $user->user_role,
            ],
        ]);
    }


    /**
     * Step 4: LOGIN to the account [Public Flow].
     * * Authenticates the user via phone number and 4-digit PIN.
     * If successful, issues a Sanctum PlainTextToken for API access.
     * * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // 1. Validation: Ensure both fields are present
        $request->validate([
            'phone_number' => 'required|string',
            'pin'          => 'required|string|digits:4',
        ]);

        // 2. Lookup: Find the user by phone number
        $user = User::where('phone_number', $request->phone_number)->first();

        // 3. Verification: Check if user exists and PIN matches
        if (!$user || !Hash::check($request->pin, $user->pin)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid phone number or PIN.'
            ], 401);
        }

        // 4. Status Check: Only active users can log in
        if ($user->status !== 'active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Account not verified. Please verify your phone number first.'
            ], 403);
        }

        // 5. Token Generation: Issue a new Sanctum token
        $token = $user->createToken('nialink_auth_token')->plainTextToken;

        return response()->json([
            'status'       => 'success',
            'message'      => 'Login successful.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => [
                'name'         => $user->name,
                'phone_number' => $user->phone_number,
                'balance'      => $user->balance
            ]
        ]);
    }


    /**
     * Step 5: RESET PIN (Public Recovery Flow).
     * * Authenticates a user's identity via OTP to allow the
     * creation of a new secure 4-digit PIN.
     * * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPin(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|exists:users,phone_number',
            'otp'          => 'required|digits:6',
            'new_pin'      => 'required|digits:4|confirmed'
        ]);

        $resetData = DB::table('password_reset_tokens')
            ->where('phone_number', $request->phone_number)
            ->first();

        if (!$resetData || !Hash::check($request->otp, $resetData->token)) {
            return response()->json(['error' => 'Invalid or expired OTP'], 422);
        }

        // Update PIN
        $user = User::where('phone_number', $request->phone_number)->first();
        $user->update(['pin' => $request->new_pin]);

        DB::table('password_reset_tokens')->where('phone_number', $request->phone_number)->delete();

        return response()->json(['message' => 'PIN reset successfully.']);
    }


    /**
 * Step 6: CHANGE PIN (Authenticated Flow)
 * For users who are already logged in and know their current PIN.
 * This does NOT require an OTP; it requires the 'old_pin'.
 */
    public function changePin(Request $request)
    {
        $request->validate([
            'old_pin' => 'required|digits:4',
            'new_pin' => 'required|digits:4|confirmed',
        ]);

        $user = $request->user();

        // 1. Verify the old PIN matches what is in the database
        if (!Hash::check($request->old_pin, $user->pin)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The old PIN you provided is incorrect.'
            ], 401);
        }

        // 2. Update to the new PIN (Model cast handles hashing)
        $user->update([
            'pin' => $request->new_pin
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Your PIN has been updated successfully.'
        ]);
    }


    /**
 * Step 7: LOGOUT (Authenticated Flow)
 * Revokes the current access token to secure the session.
 */
public function logout(Request $request)
{
    // Revoke (delete) the token that was used to authenticate the current request
    $request->user()->currentAccessToken()->delete();

    return response()->json([
        'status' => 'success',
        'message' => 'User logged out successfully.'
    ]);
}
}
