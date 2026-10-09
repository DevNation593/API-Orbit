<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use Illuminate\Database\Eloquent\Model;

class CatalogPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'catalog.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, 'catalog.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'catalog.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, 'catalog.manage', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowed($user, 'catalog.manage', $model);
    }
}
