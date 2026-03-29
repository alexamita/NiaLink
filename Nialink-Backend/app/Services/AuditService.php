<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    /**
     * Record an audit event.
     *
     * This is the single entry point for all audit logging across NiaLink.
     * Every significant action — payments, logins, admin actions, AML flags —
     * must pass through here rather than calling AuditLog::create() directly.
     *
     * Usage:
     *   $this->auditService->log('payment_code.generated', $user);
     *   $this->auditService->log('merchant.approved', $merchant, ['by' => 'admin']);
     *   $this->auditService->log('wallet.frozen', $wallet, ['reason' => 'AML hold']);
     */
    public function log(
        string  $action,
        ?Model  $resource = null,
        array   $metadata = [],
        ?string $userId   = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id'       => $userId ?? Auth::id(),
            'action'        => $action,
            'resource_type' => $resource ? get_class($resource) : null,
            'resource_id'   => $resource?->getKey(),
            'metadata'      => empty($metadata) ? null : $metadata,
            'ip_address'    => request()?->ip(),
            'user_agent'    => request()?->userAgent(),
        ]);
    }

    /**
     * Log a model creation event.
     * Captures the full new state in metadata.
     */
    public function logCreated(Model $resource, ?string $userId = null): AuditLog
    {
        return $this->log(
            action:   $this->eventName($resource, 'created'),
            resource: $resource,
            metadata: ['attributes' => $resource->toArray()],
            userId:   $userId,
        );
    }

    /**
     * Log a model update event.
     * Captures only the changed fields — before and after values.
     *
     * Usage:
     *   // Call BEFORE $model->save() so getOriginal() still has old values
     *   $this->auditService->logUpdated($merchant, $merchant->getOriginal());
     *   $merchant->save();
     */
    public function logUpdated(Model $resource, array $original, ?string $userId = null): AuditLog
    {
        // Only record the fields that actually changed
        $changed = collect($resource->getChanges())
            ->mapWithKeys(fn($newValue, $field) => [
                $field => [
                    'from' => $original[$field] ?? null,
                    'to'   => $newValue,
                ],
            ])
            ->toArray();

        return $this->log(
            action:   $this->eventName($resource, 'updated'),
            resource: $resource,
            metadata: ['changes' => $changed],
            userId:   $userId,
        );
    }

    /**
     * Log a model deletion event.
     * Captures the final state before deletion.
     */
    public function logDeleted(Model $resource, ?string $userId = null): AuditLog
    {
        return $this->log(
            action:   $this->eventName($resource, 'deleted'),
            resource: $resource,
            metadata: ['attributes' => $resource->toArray()],
            userId:   $userId,
        );
    }

    /**
     * Log a security event with no model subject.
     * Used for: login attempts, PIN changes, device events, OTP events.
     *
     * Usage:
     *   $this->auditService->logSecurity('login.failed', ['reason' => 'invalid_pin'], $user->id);
     *   $this->auditService->logSecurity('device.trusted', ['device' => $device->id], $user->id);
     */
    public function logSecurity(string $action, array $metadata = [], ?string $userId = null): AuditLog
    {
        return $this->log(
            action:   $action,
            resource: null,
            metadata: $metadata,
            userId:   $userId,
        );
    }

    /**
     * Log a system event with no authenticated user actor.
     * Used for: scheduled jobs, reconciliation runs, queue workers.
     *
     * Usage:
     *   AuditService::system('reconciliation.completed', ['status' => 'balanced']);
     *   AuditService::system('payment_code.expired', ['count' => 14]);
     */
    public static function system(string $action, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'user_id'       => null,
            'action'        => $action,
            'resource_type' => null,
            'resource_id'   => null,
            'metadata'      => empty($metadata) ? null : $metadata,
            'ip_address'    => null,
            'user_agent'    => null,
        ]);
    }

    /**
     * Generate a consistent dot-notation event name from a model and verb.
     * Examples:
     *   Merchant + 'approved' → 'merchant.approved'
     *   Transaction + 'created' → 'transaction.created'
     *   AmlFlag + 'updated' → 'aml_flag.updated'
     */
    private function eventName(Model $resource, string $verb): string
    {
        $class = class_basename($resource);

        // Convert PascalCase to snake_case: AmlFlag → aml_flag
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        return "{$snake}.{$verb}";
    }
}
