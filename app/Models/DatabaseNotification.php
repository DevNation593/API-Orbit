<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification as LaravelDatabaseNotification;
use LogicException;

class DatabaseNotification extends LaravelDatabaseNotification
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->id();
            if ($tenantId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (self $notification): void {
            $contextTenant = app(TenantContext::class)->id();
            $payloadTenant = (int) data_get($notification->data, 'tenant_id', 0);
            $modelTenant = (int) ($notification->tenant_id ?? 0);
            $tenantId = $modelTenant ?: ($payloadTenant ?: $contextTenant);
            if ($tenantId === null || $tenantId < 1) {
                throw new LogicException('A database notification requires tenant context.');
            }
            if ($contextTenant !== null && $contextTenant !== $tenantId) {
                throw new LogicException('The notification tenant does not match the active tenant.');
            }
            $notification->tenant_id = $tenantId;
            $notification->event ??= data_get($notification->data, 'event')
                ?? data_get($notification->data, 'type');
            $notification->priority = data_get($notification->data, 'priority', $notification->priority ?? 'normal');
        });
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope('tenant')->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
