<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class TicketPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'tickets.view');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->allowed($user, 'tickets.view', $ticket);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $this->allowed($user, 'tickets.create');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $this->allowed($user, 'tickets.update', $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $this->allowed($user, 'tickets.assign', $ticket);
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $this->allowed($user, 'tickets.reply', $ticket);
    }

    public function commentInternal(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $this->allowed($user, 'tickets.comment_internal', $ticket);
    }

    public function changeStatus(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $this->allowed($user, 'tickets.change_status', $ticket);
    }
}
