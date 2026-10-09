<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ApprovalRequestPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'approvals.view');
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $this->allowed($user, 'approvals.view', $request);
    }

    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $this->allowed($user, 'approvals.decide', $request);
    }

    public function cancel(User $user, ApprovalRequest $request): bool
    {
        return $this->allowed($user, 'approvals.manage', $request)
            || ((int) $request->requested_by === (int) $user->id && $this->allowed($user, 'approvals.view', $request));
    }
}
