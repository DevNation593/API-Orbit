<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ActivityPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'activities.view');
    }

    public function view(User $user, Activity $model): bool
    {
        return $this->allowed($user, 'activities.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'activities.create');
    }
}
