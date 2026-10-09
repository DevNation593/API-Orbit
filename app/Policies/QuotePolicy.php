<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class QuotePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'quotes.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->allowed($user, 'quotes.view', $quote);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'quotes.create');
    }

    public function update(User $user, Quote $quote): bool
    {
        return $this->allowed($user, 'quotes.update', $quote);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $this->allowed($user, 'quotes.delete', $quote);
    }

    public function send(User $user, Quote $quote): bool
    {
        return $this->allowed($user, 'quotes.send', $quote);
    }

    public function approve(User $user, Quote $quote): bool
    {
        return $this->allowed($user, 'quotes.approve', $quote);
    }

    public function accept(User $user, Quote $quote): bool
    {
        return $this->allowed($user, 'quotes.accept', $quote);
    }
}
