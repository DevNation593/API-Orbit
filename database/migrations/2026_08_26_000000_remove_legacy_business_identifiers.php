<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyColumns = [
            'tenants' => 'slug',
            'roles' => 'slug',
            'pipeline_stages' => 'slug',
            'entity_definitions' => 'slug',
        ];

        $needsFieldRelation = ! Schema::hasColumn('field_definitions', 'entity_definition_id');
        $hasLegacyColumns = collect($legacyColumns)
            ->contains(fn (string $column, string $table): bool => Schema::hasColumn($table, $column));

        if (! $needsFieldRelation && ! $hasLegacyColumns) {
            return;
        }

        $forcedRlsTables = $this->disableForcedRls(['field_definitions', 'entity_definitions']);

        try {
            if ($needsFieldRelation) {
                Schema::table('field_definitions', function (Blueprint $table): void {
                    $table->unsignedBigInteger('entity_definition_id')->nullable()->after('entity_type');
                });
            }
            if (Schema::hasColumn('entity_definitions', 'slug')) {
                Schema::table('field_definitions', function (Blueprint $table): void {
                    $table->string('entity_type')->nullable()->change();
                });
            }

            if (Schema::hasColumn('entity_definitions', 'slug')) {
                DB::table('entity_definitions')
                    ->select(['id', 'tenant_id', 'slug'])
                    ->orderBy('id')
                    ->each(function (object $definition): void {
                        DB::table('field_definitions')
                            ->where('tenant_id', $definition->tenant_id)
                            ->where('entity_type', $definition->slug)
                            ->update([
                                'entity_type' => null,
                                'entity_definition_id' => $definition->id,
                            ]);
                    });
            }

            if ($needsFieldRelation) {
                Schema::table('field_definitions', function (Blueprint $table): void {
                    $table->foreign('entity_definition_id')->references('id')->on('entity_definitions')->cascadeOnDelete();
                });
            }

            if (! Schema::hasIndex('field_definitions', 'field_definitions_custom_name_unique')) {
                Schema::table('field_definitions', function (Blueprint $table): void {
                    $table->unique(['tenant_id', 'entity_definition_id', 'name'], 'field_definitions_custom_name_unique');
                });
            }
            if (! Schema::hasIndex('field_definitions', 'field_definitions_custom_active_index')) {
                Schema::table('field_definitions', function (Blueprint $table): void {
                    $table->index(['tenant_id', 'entity_definition_id', 'active'], 'field_definitions_custom_active_index');
                });
            }

            $this->dropLegacyColumn('tenants', 'slug', 'tenants_slug_unique');
            $this->dropLegacyColumn('roles', 'slug', 'roles_tenant_id_slug_unique');
            $this->dropLegacyColumn('pipeline_stages', 'slug', 'pipeline_stages_pipeline_id_slug_unique');
            $this->dropLegacyColumn('entity_definitions', 'slug', 'entity_definitions_tenant_id_slug_unique');
        } finally {
            $this->restoreForcedRls($forcedRlsTables);
        }
    }

    public function down(): void
    {
        // This cleanup is intentionally one-way: rolling it back would restore
        // redundant identifiers that are no longer part of the domain model.
    }

    /** @return list<string> */
    private function disableForcedRls(array $tables): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $forced = [];
        foreach ($tables as $table) {
            if (DB::table('pg_class')->where('relname', $table)->where('relforcerowsecurity', true)->exists()) {
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                $forced[] = $table;
            }
        }

        return $forced;
    }

    private function restoreForcedRls(array $tables): void
    {
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    private function dropLegacyColumn(string $tableName, string $column, string $uniqueIndex): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        if (Schema::hasIndex($tableName, $uniqueIndex)) {
            Schema::table($tableName, function (Blueprint $table) use ($uniqueIndex): void {
                $table->dropUnique($uniqueIndex);
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
    }
};
