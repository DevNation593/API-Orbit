<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'forms.view', 'forms.manage', 'form_submissions.view',
        'lead_routing.view', 'lead_routing.manage',
        'lead_scoring.view', 'lead_scoring.manage',
    ];

    private const TENANT_TABLES = [
        'forms', 'form_fields', 'form_submissions', 'lead_capture_events',
        'lead_routing_rules', 'lead_routing_conditions', 'lead_routing_actions', 'lead_routing_executions',
        'scoring_models', 'scoring_rules', 'scoring_conditions', 'scoring_events', 'lead_scores',
    ];

    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('capture_origin', 30)->default('manual')->after('source');
            $table->string('email_normalized')->nullable()->after('email');
            $table->string('phone_normalized', 32)->nullable()->after('phone');
            $table->string('medium', 190)->nullable()->after('capture_origin');
            $table->string('campaign', 190)->nullable()->after('medium');
            $table->string('content', 190)->nullable()->after('campaign');
            $table->string('term', 190)->nullable()->after('content');
            $table->string('utm_source', 190)->nullable()->after('term');
            $table->string('utm_medium', 190)->nullable()->after('utm_source');
            $table->string('utm_campaign', 190)->nullable()->after('utm_medium');
            $table->string('utm_content', 190)->nullable()->after('utm_campaign');
            $table->string('utm_term', 190)->nullable()->after('utm_content');
            $table->text('landing_page')->nullable()->after('utm_term');
            $table->text('referrer')->nullable()->after('landing_page');
            $table->json('first_touch')->nullable()->after('referrer');
            $table->json('last_touch')->nullable()->after('first_touch');
            $table->string('score_classification', 20)->nullable()->after('score');
            $table->timestamp('routed_at')->nullable()->after('score_classification');
            $table->timestamp('scored_at')->nullable()->after('routed_at');
            $table->index(['tenant_id', 'email_normalized'], 'leads_tenant_email_normalized_index');
            $table->index(['tenant_id', 'phone_normalized'], 'leads_tenant_phone_normalized_index');
            $table->index(['tenant_id', 'capture_origin', 'created_at'], 'leads_tenant_origin_created_index');
            $table->index(['tenant_id', 'score_classification', 'score'], 'leads_tenant_classification_score_index');
        });

        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('settings')->nullable();
            $table->text('success_message')->nullable();
            $table->text('redirect_url')->nullable();
            $table->unsignedBigInteger('submissions_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name'], 'forms_tenant_name_unique');
            $table->index(['public_id', 'status'], 'forms_public_status_index');
            $table->index(['tenant_id', 'status', 'created_at'], 'forms_tenant_status_index');
        });

        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field_key', 120);
            $table->string('label', 190);
            $table->string('type', 30);
            $table->string('mapping_target', 190)->nullable();
            $table->string('placeholder', 255)->nullable();
            $table->text('help_text')->nullable();
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('default_value')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'form_id', 'field_key'], 'form_fields_form_key_unique');
            $table->index(['tenant_id', 'form_id', 'active', 'position'], 'form_fields_lookup_index');
        });

        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('status', 30)->default('received');
            $table->text('payload');
            $table->json('attribution')->nullable();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->unsignedTinyInteger('spam_score')->default(0);
            $table->boolean('captcha_verified')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'form_id', 'idempotency_key_hash'], 'form_submissions_idempotency_unique');
            $table->index(['tenant_id', 'form_id', 'status', 'created_at'], 'form_submissions_lookup_index');
        });

        Schema::create('lead_capture_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin', 30);
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->json('attribution')->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'origin', 'idempotency_key_hash'], 'lead_capture_events_idempotency_unique');
            $table->index(['tenant_id', 'lead_id', 'occurred_at'], 'lead_capture_events_lead_index');
            $table->index(['tenant_id', 'origin', 'occurred_at'], 'lead_capture_events_origin_index');
        });

        Schema::create('lead_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fallback_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('strategy', 30);
            $table->string('match_type', 10)->default('all');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedInteger('cursor')->default(0);
            $table->json('config')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('stop_on_match')->default(true);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'lead_routing_rules_tenant_name_unique');
            $table->index(['tenant_id', 'active', 'priority'], 'lead_routing_rules_priority_index');
        });

        Schema::create('lead_routing_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_routing_rule_id')->constrained()->cascadeOnDelete();
            $table->string('field', 190);
            $table->string('operator', 30);
            $table->json('value')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'lead_routing_rule_id', 'position'], 'lead_routing_conditions_lookup_index');
        });

        Schema::create('lead_routing_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_routing_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('candidate');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedBigInteger('assignments_count')->default(0);
            $table->timestamp('last_assigned_at')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'lead_routing_rule_id', 'position'], 'lead_routing_actions_lookup_index');
            $table->index(['tenant_id', 'user_id', 'last_assigned_at'], 'lead_routing_actions_user_index');
        });

        Schema::create('lead_routing_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_routing_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selected_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_id', 64);
            $table->string('strategy', 30)->nullable();
            $table->string('status', 30);
            $table->string('reason', 255)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'event_id'], 'lead_routing_executions_event_unique');
            $table->index(['tenant_id', 'lead_id', 'executed_at'], 'lead_routing_executions_lead_index');
            $table->index(['tenant_id', 'selected_user_id', 'executed_at'], 'lead_routing_executions_owner_index');
        });

        Schema::create('scoring_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->integer('minimum_score')->default(0);
            $table->integer('maximum_score')->default(100);
            $table->integer('warm_threshold')->default(40);
            $table->integer('hot_threshold')->default(70);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'scoring_models_tenant_name_unique');
            $table->index(['tenant_id', 'active', 'is_default'], 'scoring_models_active_index');
        });

        Schema::create('scoring_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scoring_model_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->string('event_type', 120)->nullable();
            $table->integer('points');
            $table->string('match_type', 10)->default('all');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('repeatable')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'scoring_model_id', 'name'], 'scoring_rules_model_name_unique');
            $table->index(['tenant_id', 'scoring_model_id', 'active', 'priority'], 'scoring_rules_lookup_index');
            $table->index(['tenant_id', 'event_type', 'active'], 'scoring_rules_event_index');
        });

        Schema::create('scoring_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scoring_rule_id')->constrained()->cascadeOnDelete();
            $table->string('field', 190);
            $table->string('operator', 30);
            $table->json('value')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'scoring_rule_id', 'position'], 'scoring_conditions_lookup_index');
        });

        Schema::create('scoring_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scoring_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scoring_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 120);
            $table->string('event_key', 190);
            $table->integer('points');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'scoring_rule_id', 'lead_id', 'event_key'], 'scoring_events_rule_event_unique');
            $table->index(['tenant_id', 'lead_id', 'occurred_at'], 'scoring_events_lead_index');
        });

        Schema::create('lead_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scoring_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->integer('score')->default(0);
            $table->string('classification', 20)->default('cold');
            $table->json('breakdown')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'scoring_model_id', 'lead_id'], 'lead_scores_model_lead_unique');
            $table->index(['tenant_id', 'classification', 'score'], 'lead_scores_classification_index');
        });

        $this->backfillLeads();
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

        Schema::dropIfExists('lead_scores');
        Schema::dropIfExists('scoring_events');
        Schema::dropIfExists('scoring_conditions');
        Schema::dropIfExists('scoring_rules');
        Schema::dropIfExists('scoring_models');
        Schema::dropIfExists('lead_routing_executions');
        Schema::dropIfExists('lead_routing_actions');
        Schema::dropIfExists('lead_routing_conditions');
        Schema::dropIfExists('lead_routing_rules');
        Schema::dropIfExists('lead_capture_events');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_tenant_email_normalized_index');
            $table->dropIndex('leads_tenant_phone_normalized_index');
            $table->dropIndex('leads_tenant_origin_created_index');
            $table->dropIndex('leads_tenant_classification_score_index');
            $table->dropColumn([
                'capture_origin', 'email_normalized', 'phone_normalized', 'medium', 'campaign', 'content', 'term',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'landing_page', 'referrer',
                'first_touch', 'last_touch', 'score_classification', 'routed_at', 'scored_at',
            ]);
        });

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
    }

    private function backfillLeads(): void
    {
        DB::table('leads')->orderBy('id')->chunkById(250, function ($leads): void {
            foreach ($leads as $lead) {
                DB::table('leads')->where('id', $lead->id)->update([
                    'email_normalized' => $this->email($lead->email ?? null),
                    'phone_normalized' => $this->phone($lead->phone ?? null),
                    'capture_origin' => 'manual',
                ]);
            }
        });
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
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    private function hardenPostgresTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'leads' => ['first_touch', 'last_touch'],
            'forms' => ['settings'],
            'form_fields' => ['options', 'validation_rules', 'default_value', 'settings'],
            'form_submissions' => ['attribution'],
            'lead_capture_events' => ['attribution'],
            'lead_routing_rules' => ['config'],
            'lead_routing_conditions' => ['value'],
            'lead_routing_actions' => ['config'],
            'lead_routing_executions' => ['snapshot'],
            'scoring_models' => ['settings'],
            'scoring_conditions' => ['value'],
            'scoring_events' => ['metadata'],
            'lead_scores' => ['breakdown'],
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

    private function email(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function phone(mixed $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);

        return $value === '' ? null : $value;
    }
};
