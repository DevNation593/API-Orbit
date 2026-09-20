<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'support.view', 'support.manage', 'tickets.view', 'tickets.create',
        'tickets.update', 'tickets.assign', 'tickets.reply',
        'tickets.comment_internal', 'tickets.change_status', 'sla.view', 'sla.manage',
    ];

    private const TABLES = [
        'support_agents', 'ticket_categories', 'sla_business_calendars', 'sla_policies',
        'sla_rules', 'support_queues', 'support_queue_agents', 'tickets',
        'ticket_comments', 'sla_executions', 'sla_escalations',
    ];

    public function up(): void
    {
        Schema::create('support_agents', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unique(['tenant_id', 'user_id']);
        });
        Schema::create('ticket_categories', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('sla_business_calendars', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->string('timezone', 100)->default('UTC');
            $table->string('mode', 20)->default('ALWAYS');
            $table->jsonb('weekly_schedule')->nullable();
            $table->jsonb('holidays')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('sla_policies', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('calendar_id')->nullable()->constrained('sla_business_calendars')->restrictOnDelete();
            $table->boolean('pause_on_waiting_customer')->default(true);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('sla_rules', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('policy_id')->constrained('sla_policies')->restrictOnDelete();
            $table->string('priority', 20);
            $table->unsignedInteger('first_response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->unique(['tenant_id', 'policy_id', 'priority']);
        });
        Schema::create('support_queues', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('sla_policy_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('escalation_agent_id')->nullable()->constrained('support_agents')->restrictOnDelete();
        });
        Schema::create('support_queue_agents', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('queue_id')->constrained('support_queues')->restrictOnDelete();
            $table->foreignId('agent_id')->constrained('support_agents')->restrictOnDelete();
            $table->unique(['tenant_id', 'queue_id', 'agent_id']);
        });
        Schema::create('tickets', function (Blueprint $table): void {
            $this->base($table);
            $table->string('subject', 255);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('OPEN');
            $table->string('priority', 20)->default('MEDIUM');
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->restrictOnDelete();
            $table->foreignId('queue_id')->constrained('support_queues')->restrictOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('support_agents')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('resolution_summary')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->unique(['tenant_id', 'idempotency_key'], 'support_ticket_key_unique');
            $table->index(['tenant_id', 'status', 'priority']);
            $table->index(['tenant_id', 'queue_id', 'status']);
            $table->index(['tenant_id', 'assigned_agent_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('ticket_comments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('visibility', 20);
            $table->text('body');
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->unique(['tenant_id', 'ticket_id', 'idempotency_key'], 'support_comment_key_unique');
            $table->index(['tenant_id', 'ticket_id', 'id']);
        });
        Schema::create('sla_executions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('ticket_id')->unique()->constrained()->restrictOnDelete();
            $table->jsonb('snapshot');
            $table->string('status', 20)->default('RUNNING');
            foreach ([
                'first_response_due_at', 'first_response_at', 'resolution_due_at',
                'last_resolution_due_at', 'resolved_at', 'paused_at', 'resolution_anchor_at',
                'first_response_breached_at', 'resolution_breached_at',
            ] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->unsignedBigInteger('resolution_remaining_seconds')->default(0);
            $table->boolean('first_response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->index(['tenant_id', 'status', 'first_response_due_at'], 'support_first_response_due_index');
            $table->index(['tenant_id', 'status', 'resolution_due_at'], 'support_resolution_due_index');
        });
        Schema::create('sla_escalations', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('execution_id')->constrained('sla_executions')->restrictOnDelete();
            $table->string('metric', 20);
            $table->timestamp('breached_at');
            $table->timestamp('detected_at');
            $table->string('status', 30)->default('pending');
            $table->jsonb('recipients');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->uuid('reservation_token')->nullable();
            $table->text('last_error')->nullable();
            $table->unique(['tenant_id', 'execution_id', 'metric'], 'support_escalation_unique');
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'support_escalation_dispatch_index');
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
