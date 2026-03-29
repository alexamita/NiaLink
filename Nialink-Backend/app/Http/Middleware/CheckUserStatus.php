<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Reject requests from accounts that are not in 'active' status.
     *
     * Applied to all protected routes — both consumer and admin.
     * Runs after auth:sanctum so $request->user() is always available.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->status !== 'active') {
            return response()->json([
                'message' => match ($user->status) {
                    'suspended' => 'Your account has been suspended. Please contact support.',
                    'flagged'   => 'Your account has been flagged for review.',
                    'closed'    => 'This account is closed.',
                    default     => 'Your account is not active.',
                },
                'code'    => 'ACCOUNT_' . strtoupper($user->status),
            ], 403);
        }

        return $next($request);
    }
}
