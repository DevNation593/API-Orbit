<?php

namespace App\Policies;

use App\Models\Sequence;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class SequencePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'sequences.view');
    }

    public function view(User $user, Sequence $sequence): bool
    {
        return $this->allowed($user, 'sequences.view', $sequence);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'sequences.manage');
    }

    public function update(User $user, Sequence $sequence): bool
    {
        return $this->allowed($user, 'sequences.manage', $sequence);
    }

    public function delete(User $user, Sequence $sequence): bool
    {
        return $this->allowed($user, 'sequences.manage', $sequence);
    }

    public function enroll(User $user, Sequence $sequence): bool
    {
        return $this->allowed($user, 'sequences.enroll', $sequence);
    }
}
