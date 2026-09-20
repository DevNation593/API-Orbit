<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use Illuminate\Database\Eloquent\Model;

class KnowledgePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'knowledge.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, 'knowledge.view', $model);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $this->allowed($user, 'knowledge.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model) && $this->allowed($user, 'knowledge.manage', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
