<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = [
        'contacts', 'organizations', 'contact_organization', 'pipelines', 'leads', 'deals',
        'activities', 'tasks', 'field_definitions', 'entity_definitions', 'entity_records',
        'entity_relations', 'automations', 'automation_runs', 'webhook_endpoints',
        'webhook_deliveries', 'file_records', 'import_batches', 'export_batches', 'audit_logs',
        'idempotency_keys',
    ];

    public function up(): void
    {
        if (! config('tenancy.rls_enabled') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            $policy = $table.'_tenant_isolation';
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            DB::statement("CREATE POLICY {$policy} ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            $policy = $table.'_tenant_isolation';
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
