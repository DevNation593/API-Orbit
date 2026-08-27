<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $columns = [
        'tenants' => ['settings'],
        'contacts' => ['custom_fields'],
        'organizations' => ['custom_fields'],
        'leads' => ['custom_fields'],
        'deals' => ['custom_fields'],
        'activities' => ['metadata'],
        'tasks' => ['custom_fields'],
        'field_definitions' => ['options', 'validation_rules', 'default_value'],
        'entity_definitions' => ['settings'],
        'entity_records' => ['data'],
        'entity_relations' => ['metadata'],
        'automations' => ['config'],
        'webhook_endpoints' => ['events'],
        'webhook_deliveries' => ['payload'],
        'file_records' => ['metadata'],
        'import_batches' => ['mapping', 'summary'],
        'export_batches' => ['filters'],
        'audit_logs' => ['old_values', 'new_values'],
        'idempotency_keys' => ['response_body'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE json USING {$column}::json");
            }
        }
    }
};
