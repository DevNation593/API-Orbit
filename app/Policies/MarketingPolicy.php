<?php

namespace App\Policies;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Journey;
use App\Models\Segment;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use Illuminate\Database\Eloquent\Model;

class MarketingPolicy
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

    public function send(User $user, Campaign $model): bool
    {
        return $this->allowed($user, 'campaigns.send', $model);
    }

    public function enroll(User $user, Journey $model): bool
    {
        return $this->allowed($user, 'journeys.enroll', $model);
    }

    private function prefix(Model $model): string
    {
        return match (true) {
            $model instanceof Segment => 'segments',
            $model instanceof Audience => 'audiences',
            $model instanceof Campaign => 'campaigns',
            $model instanceof Journey => 'journeys',
            default => 'consent',
        };
    }
}
