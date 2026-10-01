<?php

namespace App\Policies;

use App\Models\MeetingBooking;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class MeetingBookingPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'meetings.view');
    }

    public function view(User $user, MeetingBooking $booking): bool
    {
        return $this->allowed($user, 'meetings.view', $booking);
    }

    public function cancel(User $user, MeetingBooking $booking): bool
    {
        return $this->allowed($user, 'meetings.manage', $booking);
    }
}
