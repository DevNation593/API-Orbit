<?php

namespace App\Policies;

use App\Models\ApprovalProcess;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ApprovalProcessPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'approvals.view');
    }

    public function view(User $user, ApprovalProcess $process): bool
    {
        return $this->allowed($user, 'approvals.view', $process);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'approvals.manage');
    }

    public function update(User $user, ApprovalProcess $process): bool
    {
        return $this->allowed($user, 'approvals.manage', $process);
    }

    public function delete(User $user, ApprovalProcess $process): bool
    {
        return $this->allowed($user, 'approvals.manage', $process);
    }
}
