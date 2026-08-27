<?php

namespace App\Policies;

use App\Models\FieldDefinition;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class FieldDefinitionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'custom_fields.view');
    }

    public function view(User $user, FieldDefinition $model): bool
    {
        return $this->allowed($user, 'custom_fields.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'custom_fields.manage');
    }

    public function update(User $user, FieldDefinition $model): bool
    {
        return $this->allowed($user, 'custom_fields.manage', $model);
    }

    public function delete(User $user, FieldDefinition $model): bool
    {
        return $this->allowed($user, 'custom_fields.manage', $model);
    }
}
