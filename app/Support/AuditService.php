<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
    ): AuditLog {
        $model = $entity instanceof Model ? $entity : null;
        $request ??= request();
        $tenant = $model?->getAttribute('tenant_id') ?? $tenantId ?? app(TenantContext::class)->requireId();
        $context = app(TenantContext::class);
        $previousTenant = $context->id();
        if ($previousTenant !== (int) $tenant) {
            $context->set((int) $tenant);
        }

        try {
            return AuditLog::create([
                'tenant_id' => $tenant,
                'user_id' => auth()->id(),
                'action' => $action,
                'entity_type' => $model?->getTable() ?? (string) $entity,
                'entity_id' => (string) ($model?->getKey() ?? $entityId ?? 'unknown'),
                'old_values' => $this->redact($oldValues),
                'new_values' => $this->redact($newValues),
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'request_id' => $request?->header('X-Request-ID') ?? $request?->attributes->get('request_id'),
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

        foreach (['password', 'token', 'secret', 'credentials', 'private_key', 'api_key', 'access_token'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[REDACTED]';
            }
        }

        return $values;
    }
}
