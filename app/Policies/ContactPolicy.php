<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ContactPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'contacts.view');
    }

    public function view(User $user, Contact $model): bool
    {
        return $this->allowed($user, 'contacts.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'contacts.create');
    }

    public function update(User $user, Contact $model): bool
    {
        return $this->allowed($user, 'contacts.update', $model);
    }

    public function delete(User $user, Contact $model): bool
    {
        return $this->allowed($user, 'contacts.delete', $model);
    }
}
