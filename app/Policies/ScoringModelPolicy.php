<?php

namespace App\Policies;

use App\Models\ScoringModel;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ScoringModelPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'lead_scoring.view');
    }

    public function view(User $user, ScoringModel $model): bool
    {
        return $this->allowed($user, 'lead_scoring.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'lead_scoring.manage');
    }

    public function update(User $user, ScoringModel $model): bool
    {
        return $this->allowed($user, 'lead_scoring.manage', $model);
    }

    public function delete(User $user, ScoringModel $model): bool
    {
        return $this->allowed($user, 'lead_scoring.manage', $model);
    }
}
