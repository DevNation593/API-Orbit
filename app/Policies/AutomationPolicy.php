<?php

namespace App\Policies;

use App\Models\Automation;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class AutomationPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'automations.view');
    }

    public function view(User $user, Automation $model): bool
    {
        return $this->allowed($user, 'automations.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'automations.manage');
    }

    public function update(User $user, Automation $model): bool
    {
        return $this->allowed($user, 'automations.manage', $model);
    }

    public function delete(User $user, Automation $model): bool
    {
        return $this->allowed($user, 'automations.manage', $model);
    }
}
