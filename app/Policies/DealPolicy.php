<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class DealPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'deals.view');
    }

    public function view(User $user, Deal $model): bool
    {
        return $this->allowed($user, 'deals.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'deals.create');
    }

    public function update(User $user, Deal $model): bool
    {
        return $this->allowed($user, 'deals.update', $model);
    }

    public function delete(User $user, Deal $model): bool
    {
        return $this->allowed($user, 'deals.delete', $model);
    }
}
