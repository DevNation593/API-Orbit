<?php

namespace App\Policies;

use App\Models\EntityDefinition;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class EntityDefinitionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'custom_entities.view');
    }

    public function view(User $user, EntityDefinition $model): bool
    {
        return $this->allowed($user, 'custom_entities.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'custom_entities.manage');
    }

    public function update(User $user, EntityDefinition $model): bool
    {
        return $this->allowed($user, 'custom_entities.manage', $model);
    }

    public function delete(User $user, EntityDefinition $model): bool
    {
        return $this->allowed($user, 'custom_entities.manage', $model);
    }
}
