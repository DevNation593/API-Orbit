<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

final class AuditService
{
    public function record(
        string $action,
        Model|string $entity,
        string|int|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?int $tenantId = null,
        User|PortalUser|null $actor = null,
    ): AuditLog {
        $model = $entity instanceof Model ? $entity : null;
        $request ??= request();
        $tenant = $model?->getAttribute('tenant_id') ?? $tenantId ?? app(TenantContext::class)->requireId();
        $principal = $actor ?? $request?->user();
        if ($principal instanceof PortalUser && (int) $principal->tenant_id !== (int) $tenant) {
            throw new LogicException('The portal actor does not belong to the audit tenant.');
        }

        $context = app(TenantContext::class);
        $previousTenant = $context->id();
        if ($previousTenant !== (int) $tenant) {
            $context->set((int) $tenant);
        }

        try {
            return AuditLog::create([
                'tenant_id' => $tenant,
                'user_id' => $principal instanceof User ? $principal->id : null,
                'portal_user_id' => $principal instanceof PortalUser ? $principal->id : null,
                'action' => $action,
                'entity_type' => $model?->getTable() ?? (string) $entity,
                'entity_id' => (string) ($model?->getKey() ?? $entityId ?? 'unknown'),
                'old_values' => $this->redact($oldValues),
                'new_values' => $this->redact($newValues),
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'request_id' => $request?->header('X-Request-ID')
                    ?? $request?->attributes->get('request_id'),
            ]);
        } finally {
            $previousTenant === null ? $context->clear() : $context->set($previousTenant);
        }
    }

    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sensitive = [
            'password', 'password_confirmation', 'token', 'token_hash',
            'access_token', 'secret', 'credentials', 'private_key', 'api_key',
        ];
        foreach ($values as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $sensitive, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
