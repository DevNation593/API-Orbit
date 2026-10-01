<?php

namespace App\Policies;

use App\Models\ErpSync;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ErpSyncPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'erp_sync.view');
    }

    public function view(User $user, ErpSync $sync): bool
    {
        return $this->allowed($user, 'erp_sync.view', $sync);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'erp_sync.manage');
    }
}
