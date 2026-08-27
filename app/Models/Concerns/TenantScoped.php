<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait TenantScoped
{
    public function initializeTenantScoped(): void
    {
        $this->mergeFillable(['tenant_id']);
    }

    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('tenant_id'),
                    $tenantId,
                );
            }
        });

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();
            $modelTenantId = $model->getAttribute('tenant_id');

            if ($tenantId !== null && $modelTenantId !== null && (int) $modelTenantId !== $tenantId) {
                throw new LogicException(sprintf(
                    'The supplied tenant does not match the active tenant context for %s.',
                    $model::class,
                ));
            }

            if ($modelTenantId === null) {

                if ($tenantId === null) {
                    throw new LogicException(sprintf(
                        'Cannot create %s without an active tenant context.',
                        $model::class,
                    ));
                }

                $model->setAttribute('tenant_id', $tenantId);
            } elseif ($tenantId === null && ! app()->runningInConsole()) {
                throw new LogicException(sprintf(
                    'Cannot create %s with an explicit tenant outside an active tenant context.',
                    $model::class,
                ));
            }
        });
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope('tenant')->where($this->qualifyColumn('tenant_id'), $tenantId);
    }
}
