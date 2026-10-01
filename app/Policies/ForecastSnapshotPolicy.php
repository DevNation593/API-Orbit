<?php

namespace App\Policies;

use App\Models\ForecastSnapshot;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ForecastSnapshotPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'forecast.view');
    }

    public function view(User $user, ForecastSnapshot $snapshot): bool
    {
        return $this->allowed($user, 'forecast.view', $snapshot);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'forecast.manage');
    }
}
