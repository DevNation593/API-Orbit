<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->upsert([
            ['key' => 'integrations.view', 'description' => 'integrations view', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'integrations.manage', 'description' => 'integrations manage', 'created_at' => $now, 'updated_at' => $now],
        ], ['key'], ['description', 'updated_at']);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['integrations.view', 'integrations.manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
