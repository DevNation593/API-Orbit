<?php

namespace App\Policies;

use App\Models\Playbook;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class PlaybookPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'playbooks.view');
    }

    public function view(User $user, Playbook $playbook): bool
    {
        return $this->allowed($user, 'playbooks.view', $playbook);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'playbooks.manage');
    }

    public function update(User $user, Playbook $playbook): bool
    {
        return $this->allowed($user, 'playbooks.manage', $playbook);
    }

    public function delete(User $user, Playbook $playbook): bool
    {
        return $this->allowed($user, 'playbooks.manage', $playbook);
    }
}
