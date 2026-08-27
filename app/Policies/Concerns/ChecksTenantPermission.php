<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

trait ChecksTenantPermission
{
    protected function allowed(User $user, string $permission, ?Model $model = null): bool
    {
        if ($model !== null && (int) $model->getAttribute('tenant_id') !== (int) app(TenantContext::class)->id()) {
            return false;
        }

        return $user->hasPermission($permission);
    }
}
