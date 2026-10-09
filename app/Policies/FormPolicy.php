<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class FormPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'forms.view');
    }

    public function view(User $user, Form $form): bool
    {
        return $this->allowed($user, 'forms.view', $form);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'forms.manage');
    }

    public function update(User $user, Form $form): bool
    {
        return $this->allowed($user, 'forms.manage', $form);
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->allowed($user, 'forms.manage', $form);
    }

    public function viewSubmissions(User $user, Form $form): bool
    {
        return $this->allowed($user, 'form_submissions.view', $form);
    }
}
