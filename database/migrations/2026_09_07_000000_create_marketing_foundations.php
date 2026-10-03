<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'segments.view', 'segments.manage', 'audiences.view', 'audiences.manage',
        'consent.view', 'consent.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.send',
        'journeys.view', 'journeys.manage', 'journeys.enroll',
    ];

    private const TABLES = [
        'segments', 'segment_members', 'audiences', 'audience_members', 'consent_records',
        'consent_links', 'campaigns', 'email_campaigns', 'campaign_members', 'campaign_events',
        'journeys', 'journey_versions', 'journey_enrollments', 'journey_executions',
    ];

    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('entity_type', 30);
            $table->foreignId('entity_definition_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('definition');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('refreshed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'entity_type', 'active']);
        });
        Schema::create('segment_members', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('segment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->unique(['tenant_id', 'segment_id', 'entity_id'], 'segment_member_unique');
        });
        Schema::create('audiences', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->string('entity_type', 30);
            $table->string('type', 20)->default('static');
            $table->foreignId('segment_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamp('refreshed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('audience_members', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->unique(['tenant_id', 'audience_id', 'entity_id'], 'audience_member_unique');
        });
        Schema::create('consent_records', function (Blueprint $table): void {
            $this->base($table);
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('channel', 20);
            $table->string('destination', 254);
            $table->string('status', 10);
            $table->string('source', 120);
            $table->text('evidence')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->unique(['tenant_id', 'idempotency_key'], 'consent_idempotency_unique');
            $table->index(['tenant_id', 'channel', 'destination', 'id'], 'consent_effective_index');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'consent_subject_index');
        });
        Schema::create('consent_links', function (Blueprint $table): void {
            $this->base($table);
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->char('token_hash', 64)->unique();
            $table->jsonb('destinations');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::create('campaigns', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('public_id')->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('audience_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3);
            $table->decimal('cost', 24, 6)->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });
        Schema::create('email_campaigns', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('campaign_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->constrained()->restrictOnDelete();
            $table->string('subject', 255);
            $table->text('body');
            $table->text('body_html')->nullable();
        });
        Schema::create('campaign_members', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('destination', 254)->nullable();
            $table->char('recipient_key', 64);
            $table->string('status', 20)->default('pending');
            $table->string('skip_reason', 100)->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['tenant_id', 'campaign_id', 'recipient_key'], 'campaign_recipient_unique');
            $table->index(['tenant_id', 'campaign_id', 'status']);
        });
        Schema::create('campaign_events', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_member_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->decimal('revenue', 24, 6)->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'campaign_id', 'idempotency_key'], 'campaign_event_unique');
            $table->index(['tenant_id', 'campaign_id', 'event']);
        });
        Schema::create('journeys', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('entity_type', 30);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('published_version')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('journey_versions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('graph');
            $table->timestamp('published_at')->nullable();
            $table->unique(['tenant_id', 'journey_id', 'version'], 'journey_version_unique');
        });
        Schema::create('journey_enrollments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('journey_id')->constrained()->restrictOnDelete();
            $table->foreignId('journey_version_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('active');
            $table->string('current_node', 60);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('goal_reached_at')->nullable();
            $table->string('stop_reason', 100)->nullable();
            $table->unique(['tenant_id', 'journey_id', 'idempotency_key'], 'journey_enrollment_key_unique');
            $table->index(['tenant_id', 'status', 'next_run_at'], 'journey_due_index');
        });
        Schema::create('journey_executions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('journey_enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 60);
            $table->string('status', 20);
            $table->jsonb('output')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unique(['tenant_id', 'journey_enrollment_id', 'node_id'], 'journey_execution_unique');
        });

        $now = now();
        DB::table('permissions')->upsert(array_map(fn ($key) => [
            'key' => $key, 'description' => str_replace('.', ' ', $key),
            'created_at' => $now, 'updated_at' => $now,
        ], self::PERMISSIONS), ['key'], ['description', 'updated_at']);
        $permissions = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        $roles = DB::table('roles')->where('is_system', true)->orWhereIn('id', function ($query): void {
            $query->select('permission_role.role_id')->from('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.key', 'settings.manage');
        })->pluck('id');
        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
        if (DB::getDriverName() === 'pgsql' && config('tenancy.rls_enabled')) {
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        $permissions = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissions)->delete();
        DB::table('permissions')->whereIn('id', $permissions)->delete();
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    }
};
