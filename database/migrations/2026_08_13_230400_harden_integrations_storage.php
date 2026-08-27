<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integrations ALTER COLUMN settings TYPE jsonb USING settings::jsonb');

        if (! config('tenancy.rls_enabled')) {
            return;
        }

        DB::statement('ALTER TABLE integrations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE integrations FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS integrations_tenant_isolation ON integrations');
        DB::statement("CREATE POLICY integrations_tenant_isolation ON integrations USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS integrations_tenant_isolation ON integrations');
        DB::statement('ALTER TABLE integrations DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE integrations NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE integrations ALTER COLUMN settings TYPE json USING settings::json');
    }
};
