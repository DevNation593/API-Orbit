<?php

namespace App\Support;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;

final class PermissionCatalog
{
    public const ALL = [
        'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete',
        'organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete',
        'leads.view', 'leads.create', 'leads.update', 'leads.delete', 'leads.convert',
        'deals.view', 'deals.create', 'deals.update', 'deals.delete',
        'pipelines.view', 'pipelines.manage',
        'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete',
        'activities.view', 'activities.create',
        'custom_fields.view', 'custom_fields.manage',
        'custom_entities.view', 'custom_entities.manage',
        'relations.view', 'relations.manage',
        'automations.view', 'automations.manage',
        'files.view', 'files.create', 'files.delete',
        'imports.create', 'exports.create',
        'webhooks.view', 'webhooks.manage',
        'integrations.view', 'integrations.manage',
        'knowledge.view', 'knowledge.manage', 'knowledge.publish',
        'reports.view', 'audit.view',
        'users.manage', 'roles.manage', 'settings.manage',
    ];

    /** @return array<int, int> */
    public static function ensure(): array
    {
        $now = now();
        $rows = array_map(fn (string $key): array => [
            'key' => $key,
            'description' => str_replace('.', ' ', $key),
            'created_at' => $now,
            'updated_at' => $now,
        ], self::ALL);

        DB::table('permissions')->upsert($rows, ['key'], ['description', 'updated_at']);

        return Permission::query()->whereIn('key', self::ALL)->pluck('id')->all();
    }
}
