<?php

namespace Database\Seeders;

use App\Models\Pipeline;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = PermissionCatalog::ensure();
        $tenant = Tenant::firstOrCreate(
            ['name' => 'Demo CRM'],
            ['industry' => 'general', 'status' => 'active', 'settings' => []],
        );
        $role = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Administrator'],
            ['description' => 'Full access to the tenant.', 'is_system' => true],
        );
        $role->permissions()->sync($permissionIds);
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Demo Administrator', 'password' => 'password'],
        );
        TenantUser::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role_id' => $role->id, 'status' => 'active', 'joined_at' => now()],
        );
        $pipeline = Pipeline::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Sales'], ['is_default' => true, 'active' => true]);
        if ($pipeline->stages()->count() === 0) {
            $pipeline->stages()->createMany([
                ['name' => 'New', 'position' => 1, 'probability' => 10],
                ['name' => 'Contacted', 'position' => 2, 'probability' => 30],
                ['name' => 'Proposal', 'position' => 3, 'probability' => 60],
                ['name' => 'Won', 'position' => 4, 'probability' => 100, 'is_won' => true],
            ]);
        }
    }
}
