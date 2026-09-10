<?php

namespace App\Policies;

use App\Models\SlaBusinessCalendar;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use Illuminate\Database\Eloquent\Model;

class SupportPolicy
{
    use ChecksTenantPermission;

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, $this->prefix($model).'.view', $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, $this->prefix($model).'.manage', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    private function prefix(Model $model): string
    {
        return $model instanceof SlaPolicy || $model instanceof SlaBusinessCalendar ? 'sla' : 'support';
    }
}
