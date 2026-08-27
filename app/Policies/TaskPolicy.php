<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class TaskPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'tasks.view');
    }

    public function view(User $user, Task $model): bool
    {
        return $this->allowed($user, 'tasks.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'tasks.create');
    }

    public function update(User $user, Task $model): bool
    {
        return $this->allowed($user, 'tasks.update', $model);
    }

    public function delete(User $user, Task $model): bool
    {
        return $this->allowed($user, 'tasks.delete', $model);
    }
}
