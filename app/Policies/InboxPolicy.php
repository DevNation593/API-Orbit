<?php

namespace App\Policies;

use App\Models\Inbox;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class InboxPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'inboxes.view');
    }

    public function view(User $user, Inbox $inbox): bool
    {
        return $this->allowed($user, 'inboxes.view', $inbox);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'inboxes.manage');
    }

    public function update(User $user, Inbox $inbox): bool
    {
        return $this->allowed($user, 'inboxes.manage', $inbox);
    }
}
