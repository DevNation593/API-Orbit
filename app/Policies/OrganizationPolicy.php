<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class OrganizationPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'organizations.view');
    }

    public function view(User $user, Organization $model): bool
    {
        return $this->allowed($user, 'organizations.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'organizations.create');
    }

    public function update(User $user, Organization $model): bool
    {
        return $this->allowed($user, 'organizations.update', $model);
    }

    public function delete(User $user, Organization $model): bool
    {
        return $this->allowed($user, 'organizations.delete', $model);
    }
}
