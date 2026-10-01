<?php

namespace App\Policies;

use App\Models\MeetingType;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class MeetingTypePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'meetings.view');
    }

    public function view(User $user, MeetingType $meetingType): bool
    {
        return $this->allowed($user, 'meetings.view', $meetingType);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'meetings.manage');
    }

    public function update(User $user, MeetingType $meetingType): bool
    {
        return $this->allowed($user, 'meetings.manage', $meetingType);
    }

    public function delete(User $user, MeetingType $meetingType): bool
    {
        return $this->allowed($user, 'meetings.manage', $meetingType);
    }
}
