<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'sales_structure.view', 'sales_structure.manage',
        'territories.view', 'territories.manage',
        'goals.view', 'goals.manage',
        'forecast.view', 'forecast.manage',
        'playbooks.view', 'playbooks.manage', 'playbooks.execute',
        'analytics.view',
    ];

    private const TENANT_TABLES = [
        'branches', 'sales_teams', 'branch_members', 'sales_team_members',
        'territories', 'territory_members', 'territory_rules', 'territory_assignments',
        'goals', 'goal_targets', 'goal_progress', 'forecast_snapshots',
        'playbooks', 'playbook_sections', 'playbook_questions', 'playbook_executions',
        'playbook_answers', 'playbook_action_logs',
    ];

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'parent_id', 'active'], 'branches_tree_index');
        });

        Schema::create('sales_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('sales_teams')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'branch_id', 'active'], 'sales_teams_branch_index');
        });

        Schema::create('branch_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('member');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'user_id'], 'branch_members_unique');
            $table->index(['tenant_id', 'user_id', 'active'], 'branch_members_user_index');
        });

        Schema::create('sales_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('member');
            $table->decimal('quota_weight', 10, 6)->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sales_team_id', 'user_id'], 'sales_team_members_unique');
            $table->index(['tenant_id', 'user_id', 'active'], 'sales_team_members_user_index');
        });

        Schema::create('territories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('type', 30)->default('geographic');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'parent_id', 'position'], 'territories_tree_index');
            $table->index(['tenant_id', 'branch_id', 'active'], 'territories_branch_index');
        });

        Schema::create('territory_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('member');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'territory_id', 'user_id'], 'territory_members_unique');
            $table->index(['tenant_id', 'user_id', 'active'], 'territory_members_user_index');
        });

        Schema::create('territory_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('entity_type', 30);
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions');
            $table->string('match_type', 10)->default('all');
            $table->boolean('stop_processing')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'territory_rules_tenant_name_unique');
            $table->index(['tenant_id', 'entity_type', 'active', 'priority'], 'territory_rules_match_index');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreignId('territory_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'territory_id'], 'contacts_territory_index');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreignId('territory_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
            $table->string('industry', 120)->nullable()->after('territory_id');
            $table->index(['tenant_id', 'territory_id'], 'organizations_territory_index');
            $table->index(['tenant_id', 'industry'], 'organizations_industry_index');
        });
        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('territory_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'territory_id'], 'leads_territory_index');
        });
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignId('sales_team_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('sales_team_id')->constrained()->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->string('forecast_category', 30)->default('pipeline')->after('status');
            $table->timestamp('closed_at')->nullable()->after('expected_close_date');
            $table->index(['tenant_id', 'sales_team_id', 'status'], 'deals_team_status_index');
            $table->index(['tenant_id', 'branch_id', 'status'], 'deals_branch_status_index');
            $table->index(['tenant_id', 'territory_id', 'status'], 'deals_territory_status_index');
            $table->index(['tenant_id', 'forecast_category', 'expected_close_date'], 'deals_forecast_index');
            $table->index(['tenant_id', 'status', 'closed_at'], 'deals_closed_index');
        });

        Schema::create('territory_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('assignable');
            $table->string('source', 20)->default('manual');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'assignable_type', 'assignable_id'], 'territory_assignments_current_unique');
            $table->index(['tenant_id', 'territory_id', 'assignable_type'], 'territory_assignments_territory_index');
        });

        Schema::create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 190);
            $table->string('metric', 40);
            $table->string('period_type', 20)->default('custom');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 20)->default('draft');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'metric', 'status', 'starts_at', 'ends_at'], 'goals_period_index');
        });

        Schema::create('goal_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_key', 190)->nullable();
            $table->decimal('target_value', 24, 6);
            $table->decimal('weight', 10, 6)->default(1);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'target_type', 'target_id'], 'goal_targets_scope_index');
            $table->index(['tenant_id', 'target_type', 'target_key'], 'goal_targets_key_index');
        });

        Schema::create('goal_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_target_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 24, 6);
            $table->decimal('actual_value', 24, 6)->default(0);
            $table->decimal('completion_percentage', 14, 6)->default(0);
            $table->timestamp('calculated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'goal_target_id', 'snapshot_date'], 'goal_progress_daily_unique');
            $table->index(['tenant_id', 'snapshot_date'], 'goal_progress_snapshot_index');
        });

        Schema::create('forecast_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('pipeline_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_type', 20)->default('tenant');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('as_of_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('pipeline', 24, 6)->default(0);
            $table->decimal('weighted_pipeline', 24, 6)->default(0);
            $table->decimal('commit', 24, 6)->default(0);
            $table->decimal('best_case', 24, 6)->default(0);
            $table->decimal('closed_won', 24, 6)->default(0);
            $table->decimal('target', 24, 6)->default(0);
            $table->decimal('coverage', 14, 6)->default(0);
            $table->json('breakdown')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'as_of_date', 'scope_type', 'scope_id'], 'forecast_snapshots_scope_index');
        });

        Schema::create('playbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 190);
            $table->string('entity_type', 30);
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'entity_type', 'active'], 'playbooks_entity_index');
        });

        Schema::create('playbook_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->unsignedInteger('position');
            $table->boolean('required')->default(true);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'playbook_id', 'position'], 'playbook_sections_position_unique');
        });

        Schema::create('playbook_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_section_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('prompt', 500);
            $table->text('help_text')->nullable();
            $table->string('type', 30)->default('text');
            $table->unsignedInteger('position');
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->json('score_config')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'playbook_section_id', 'key'], 'playbook_questions_key_unique');
            $table->unique(['tenant_id', 'playbook_section_id', 'position'], 'playbook_questions_position_unique');
        });

        Schema::create('playbook_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_id')->constrained()->restrictOnDelete();
            $table->morphs('executable');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('in_progress');
            $table->decimal('score', 14, 6)->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'assigned_to', 'status'], 'playbook_executions_assignee_index');
            $table->index(['tenant_id', 'playbook_id', 'status'], 'playbook_executions_playbook_index');
        });

        Schema::create('playbook_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_question_id')->constrained()->restrictOnDelete();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('value');
            $table->decimal('score', 14, 6)->default(0);
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'playbook_execution_id', 'playbook_question_id'], 'playbook_answers_unique');
        });

        Schema::create('playbook_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_answer_id')->constrained()->cascadeOnDelete();
            $table->string('action_key', 190);
            $table->string('type', 40);
            $table->string('status', 20);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'playbook_answer_id', 'action_key'], 'playbook_action_logs_unique');
        });

        $this->createPartialUniqueIndexes();
        $this->installPermissions();
        $this->hardenPostgresTables();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TENANT_TABLES as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            }
        }

        foreach (['playbook_action_logs', 'playbook_answers', 'playbook_executions', 'playbook_questions', 'playbook_sections', 'playbooks', 'forecast_snapshots', 'goal_progress', 'goal_targets', 'goals', 'territory_assignments'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_team_status_index');
            $table->dropIndex('deals_branch_status_index');
            $table->dropIndex('deals_territory_status_index');
            $table->dropIndex('deals_forecast_index');
            $table->dropIndex('deals_closed_index');
            $table->dropConstrainedForeignId('sales_team_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('territory_id');
            $table->dropColumn(['forecast_category', 'closed_at']);
        });
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_territory_index');
            $table->dropConstrainedForeignId('territory_id');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('organizations_territory_index');
            $table->dropIndex('organizations_industry_index');
            $table->dropConstrainedForeignId('territory_id');
            $table->dropColumn('industry');
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_territory_index');
            $table->dropConstrainedForeignId('territory_id');
        });

        foreach (['territory_rules', 'territory_members', 'territories', 'sales_team_members', 'branch_members', 'sales_teams', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
    }

    private function createPartialUniqueIndexes(): void
    {
        foreach ([
            'branches_tenant_code_unique' => ['branches', 'tenant_id, code'],
            'sales_teams_tenant_name_unique' => ['sales_teams', 'tenant_id, name'],
            'territories_tenant_code_unique' => ['territories', 'tenant_id, code'],
            'goals_tenant_period_name_unique' => ['goals', 'tenant_id, name, starts_at, ends_at'],
            'playbooks_tenant_name_version_unique' => ['playbooks', 'tenant_id, name, version'],
        ] as $index => [$table, $columns]) {
            DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$columns}) WHERE deleted_at IS NULL");
        }
    }

    private function installPermissions(): void
    {
        $now = now();
        DB::table('permissions')->upsert(array_map(fn (string $key): array => [
            'key' => $key,
            'description' => str_replace('.', ' ', $key),
            'created_at' => $now,
            'updated_at' => $now,
        ], self::PERMISSIONS), ['key'], ['description', 'updated_at']);
        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        $roleIds = DB::table('roles')->where('is_system', true)
            ->orWhereIn('id', function ($query): void {
                $query->select('permission_role.role_id')->from('permission_role')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permissions.key', 'settings.manage');
            })->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    private function hardenPostgresTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ([
            'branches' => ['settings'], 'sales_teams' => ['settings'], 'territories' => ['settings'],
            'territory_rules' => ['conditions'], 'territory_assignments' => ['metadata'], 'goals' => ['settings'],
            'goal_targets' => ['settings'], 'goal_progress' => ['metadata'], 'forecast_snapshots' => ['breakdown'],
            'playbooks' => ['settings'], 'playbook_sections' => ['conditions'],
            'playbook_questions' => ['options', 'validation', 'score_config', 'actions'],
            'playbook_executions' => ['metadata'], 'playbook_answers' => ['value'], 'playbook_action_logs' => ['result'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
            }
        }
        if (! config('tenancy.rls_enabled')) {
            return;
        }
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }
};
