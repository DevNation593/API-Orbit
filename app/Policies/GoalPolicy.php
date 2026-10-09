<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use Illuminate\Database\Eloquent\Model;

class GoalPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'goals.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, 'goals.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'goals.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, 'goals.manage', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowed($user, 'goals.manage', $model);
    }
}
