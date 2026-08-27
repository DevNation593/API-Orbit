<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class LeadPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'leads.view');
    }

    public function view(User $user, Lead $model): bool
    {
        return $this->allowed($user, 'leads.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'leads.create');
    }

    public function update(User $user, Lead $model): bool
    {
        return $this->allowed($user, 'leads.update', $model);
    }

    public function delete(User $user, Lead $model): bool
    {
        return $this->allowed($user, 'leads.delete', $model);
    }

    public function convert(User $user, Lead $model): bool
    {
        return $this->allowed($user, 'leads.convert', $model);
    }
}
