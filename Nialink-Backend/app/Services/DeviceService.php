<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;

class DeviceService
{
    /**
     * Trust a device after successful OTP verification.
     *
     * Enforces the one-device-one-user rule:
     *   - If another user is active on this device, they are superseded
     *     and their Sanctum tokens are deleted immediately.
     *   - If this user has another active device, that device is superseded.
     *   - The incoming device is then set to active + trusted.
     *
     * Everything runs in a single DB transaction — there is never a moment
     * where two users are simultaneously active on the same device.
     */
    public function trustDevice(User $user, string $deviceId): UserDevice
    {
        return DB::transaction(function () use ($user, $deviceId) {

            // Find any currently active row for this device globally
            $currentlyActive = UserDevice::where('device_id', $deviceId)
                ->where('status', 'active')
                ->first();

            if ($currentlyActive) {
                // Supersede the current active row
                $currentlyActive->supersede();

                // If it belonged to a DIFFERENT user, revoke their API tokens
                // so their session ends immediately on their next request
                if ($currentlyActive->user_id !== $user->id) {
                    $currentlyActive->user->tokens()->delete();

                    // Optionally notify the displaced user:
                    // $currentlyActive->user->notify(new DeviceDisplacedNotification());
                }
            }

            // Supersede any other device this user has active on a different phone
            // Enforces: one user, one active device
            UserDevice::where('user_id', $user->id)
                ->where('device_id', '!=', $deviceId)
                ->where('status', 'active')
                ->each(fn ($d) => $d->supersede());

            // Activate and trust the incoming device
            $device = UserDevice::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->firstOrFail();

            $device->update([
                'status'     => 'active',
                'is_trusted' => true,
                'trusted_at' => now(),
            ]);

            return $device->fresh();
        });
    }

    /**
     * Check if the request is coming from the user's active trusted device.
     * Called in RequireActiveDevice middleware on every protected request.
     */
    public function isActiveDevice(User $user, string $deviceId): bool
    {
        return UserDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->where('status', 'active')
            ->where('is_trusted', true)
            ->exists();
    }

    /**
     * Revoke a device — called on logout or admin action.
     * Deletes all Sanctum tokens so the API session ends immediately.
     */
    public function revokeDevice(User $user, string $deviceId): void
    {
        UserDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->each(fn ($d) => $d->revoke());

        $user->tokens()->delete();
    }

    /**
     * Check whether a device is currently active under a different user.
     * Used during registration to warn the incoming user that another
     * account will be displaced before proceeding.
     */
    public function isOwnedByAnotherUser(string $deviceId, ?string $excludeUserId = null): bool
    {
        return UserDevice::where('device_id', $deviceId)
            ->where('status', 'active')
            ->when($excludeUserId, fn ($q) => $q->where('user_id', '!=', $excludeUserId))
            ->exists();
    }
}
