<?php

namespace App\Policies;

use App\Models\LeadRoutingRule;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class LeadRoutingRulePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'lead_routing.view');
    }

    public function view(User $user, LeadRoutingRule $rule): bool
    {
        return $this->allowed($user, 'lead_routing.view', $rule);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'lead_routing.manage');
    }

    public function update(User $user, LeadRoutingRule $rule): bool
    {
        return $this->allowed($user, 'lead_routing.manage', $rule);
    }

    public function delete(User $user, LeadRoutingRule $rule): bool
    {
        return $this->allowed($user, 'lead_routing.manage', $rule);
    }
}
