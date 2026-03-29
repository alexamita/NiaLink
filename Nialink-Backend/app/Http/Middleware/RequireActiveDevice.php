<?php

namespace App\Http\Middleware;

use App\Services\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveDevice
{
    public function __construct(protected DeviceService $deviceService) {}

    /**
     * Reject any request that does not come from the user's
     * active trusted device.
     *
     * Applied to consumer routes only — admin/merchant routes
     * do not require device binding.
     *
     * The mobile app must send the device's hardware fingerprint
     * in the X-Device-ID header on every request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user     = $request->user();
        $deviceId = $request->header('X-Device-ID');

        if (! $deviceId) {
            return response()->json([
                'message' => 'Device identification required.',
                'code'    => 'DEVICE_ID_MISSING',
            ], 403);
        }

        if (! $this->deviceService->isActiveDevice($user, $deviceId)) {
            return response()->json([
                'message' => 'This device is no longer active. Please log in again.',
                'code'    => 'DEVICE_NOT_ACTIVE',
            ], 403);
        }

        return $next($request);
    }
}
