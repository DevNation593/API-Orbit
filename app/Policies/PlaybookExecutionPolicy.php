<?php

namespace App\Policies;

use App\Models\PlaybookExecution;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class PlaybookExecutionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'playbooks.execute');
    }

    public function view(User $user, PlaybookExecution $execution): bool
    {
        return $this->allowed($user, 'playbooks.execute', $execution);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'playbooks.execute');
    }

    public function update(User $user, PlaybookExecution $execution): bool
    {
        return $this->allowed($user, 'playbooks.execute', $execution);
    }
}
