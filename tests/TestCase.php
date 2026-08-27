<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return array{user: User, tenant: Tenant, token: string} */
    protected function createTenantUser(array $permissions = PermissionCatalog::ALL): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $role = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test role',
            'is_system' => false,
        ]);
        PermissionCatalog::ensure();
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return ['user' => $user, 'tenant' => $tenant, 'token' => $user->createToken('test')->plainTextToken];
    }
}
