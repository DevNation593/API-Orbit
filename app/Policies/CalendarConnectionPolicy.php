<?php

namespace App\Policies;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class CalendarConnectionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'calendar_connections.manage');
    }

    public function view(User $user, CalendarConnection $connection): bool
    {
        return $this->allowed($user, 'calendar_connections.manage', $connection);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'calendar_connections.manage');
    }

    public function update(User $user, CalendarConnection $connection): bool
    {
        return $this->allowed($user, 'calendar_connections.manage', $connection);
    }

    public function delete(User $user, CalendarConnection $connection): bool
    {
        return $this->allowed($user, 'calendar_connections.manage', $connection);
    }
}
