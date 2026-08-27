<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class PipelinePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'pipelines.view');
    }

    public function view(User $user, Pipeline $model): bool
    {
        return $this->allowed($user, 'pipelines.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'pipelines.manage');
    }

    public function update(User $user, Pipeline $model): bool
    {
        return $this->allowed($user, 'pipelines.manage', $model);
    }

    public function delete(User $user, Pipeline $model): bool
    {
        return $this->allowed($user, 'pipelines.manage', $model);
    }
}
