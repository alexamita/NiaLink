<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Services\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function __construct(protected UserAuthService $authService) {}

    /**
     * Login a merchant admin or staff member.
     * Requires email + password. No device binding — web-based.
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->loginAdmin(
                $request->email,
                $request->password
            );
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return response()->json($result, 200);
    }

    /**
     * Logout — revokes all Sanctum tokens for this user.
     * Web sessions are also cleared.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully.'], 200);
    }
}
