<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new LogicException('A tenant id must be a positive integer.');
        }

        $this->tenantId = $tenantId;

        if (config('tenancy.rls_enabled') && DB::getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        }
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function requireId(): int
    {
        return $this->tenantId ?? throw new LogicException('No tenant has been resolved for this request.');
    }

    public function clear(): void
    {
        if ($this->tenantId !== null && config('tenancy.rls_enabled') && DB::getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.tenant_id', '', false)");
        }

        $this->tenantId = null;
    }
}
