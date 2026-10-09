<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'sequences.view', 'sequences.manage', 'sequences.enroll',
        'meetings.view', 'meetings.manage', 'calendar_connections.manage',
    ];

    private const TENANT_TABLES = [
        'sequences', 'sequence_steps', 'sequence_enrollments', 'sequence_executions',
        'meeting_types', 'availability_rules', 'availability_exclusions', 'calendar_connections',
        'meeting_bookings', 'meeting_participants',
    ];

    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('stop_conditions')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('enrollments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name'], 'sequences_tenant_name_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'sequences_tenant_status_index');
        });

        Schema::create('sequence_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 30);
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->json('config');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sequence_id', 'position'], 'sequence_steps_position_unique');
            $table->index(['tenant_id', 'sequence_id', 'active'], 'sequence_steps_lookup_index');
        });

        Schema::create('sequence_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('current_position')->default(0);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'next_run_at'], 'sequence_enrollments_due_index');
            $table->index(['tenant_id', 'sequence_id', 'status'], 'sequence_enrollments_sequence_index');
            $table->index(['tenant_id', 'lead_id', 'status'], 'sequence_enrollments_lead_index');
            $table->index(['tenant_id', 'contact_id', 'status'], 'sequence_enrollments_contact_index');
        });

        Schema::create('sequence_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_step_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 190);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status', 20)->default('queued');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'sequence_executions_idempotency_unique');
            $table->index(['tenant_id', 'sequence_enrollment_id', 'status'], 'sequence_executions_enrollment_index');
        });

        Schema::create('meeting_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('timezone', 80)->default('UTC');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('minimum_notice_minutes')->default(60);
            $table->unsignedSmallInteger('maximum_days_ahead')->default(60);
            $table->string('assignment_strategy', 20)->default('fixed');
            $table->unsignedInteger('assignment_cursor')->default(0);
            $table->string('location_type', 30)->default('custom');
            $table->json('location_details')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name'], 'meeting_types_tenant_name_unique');
            $table->index(['public_id', 'active'], 'meeting_types_public_active_index');
            $table->index(['tenant_id', 'active', 'created_at'], 'meeting_types_tenant_active_index');
        });

        Schema::create('availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 80);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_type_id', 'day_of_week', 'active'], 'availability_rules_lookup_index');
            $table->index(['tenant_id', 'user_id', 'active'], 'availability_rules_user_index');
        });

        Schema::create('availability_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_type_id', 'starts_at', 'ends_at'], 'availability_exclusions_lookup_index');
            $table->index(['tenant_id', 'user_id', 'starts_at'], 'availability_exclusions_user_index');
        });

        Schema::create('calendar_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('external_calendar_id', 190)->default('primary');
            $table->string('timezone', 80)->default('UTC');
            $table->json('settings')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'provider', 'external_calendar_id'], 'calendar_connections_unique');
            $table->index(['tenant_id', 'user_id', 'status'], 'calendar_connections_user_index');
        });

        Schema::create('meeting_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('calendar_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('meeting_bookings')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->text('manage_token')->nullable();
            $table->char('manage_token_hash', 64)->unique();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->string('invitee_name', 190);
            $table->string('invitee_email', 190);
            $table->string('invitee_phone', 50)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 80);
            $table->string('status', 30)->default('confirmed');
            $table->string('sync_status', 20)->default('pending');
            $table->string('provider', 30)->nullable();
            $table->string('external_event_id', 190)->nullable();
            $table->text('conference_url')->nullable();
            $table->text('location')->nullable();
            $table->text('notes')->nullable();
            $table->text('sync_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_type_id', 'idempotency_key_hash'], 'meeting_bookings_idempotency_unique');
            $table->index(['tenant_id', 'host_user_id', 'status', 'starts_at', 'ends_at'], 'meeting_bookings_host_time_index');
            $table->index(['tenant_id', 'meeting_type_id', 'status', 'starts_at'], 'meeting_bookings_type_time_index');
            $table->index(['tenant_id', 'contact_id', 'starts_at'], 'meeting_bookings_contact_index');
            $table->index(['tenant_id', 'lead_id', 'starts_at'], 'meeting_bookings_lead_index');
            $table->index(['tenant_id', 'sync_status', 'created_at'], 'meeting_bookings_sync_index');
        });

        Schema::create('meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('type', 20)->default('guest');
            $table->string('response_status', 20)->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_booking_id', 'type'], 'meeting_participants_lookup_index');
            $table->index(['tenant_id', 'email'], 'meeting_participants_email_index');
        });

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

        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meeting_bookings');
        Schema::dropIfExists('calendar_connections');
        Schema::dropIfExists('availability_exclusions');
        Schema::dropIfExists('availability_rules');
        Schema::dropIfExists('meeting_types');
        Schema::dropIfExists('sequence_executions');
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
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
            'sequences' => ['stop_conditions', 'settings'],
            'sequence_steps' => ['config'],
            'sequence_enrollments' => ['metadata'],
            'sequence_executions' => ['output'],
            'meeting_types' => ['location_details', 'settings'],
            'calendar_connections' => ['settings'],
            'meeting_bookings' => ['metadata'],
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
