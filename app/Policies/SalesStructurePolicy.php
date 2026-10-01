<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use Illuminate\Database\Eloquent\Model;

class SalesStructurePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'sales_structure.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, 'sales_structure.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'sales_structure.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, 'sales_structure.manage', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowed($user, 'sales_structure.manage', $model);
    }
}
