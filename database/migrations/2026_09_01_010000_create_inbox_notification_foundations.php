<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'notifications.view',
        'notifications.manage',
        'inboxes.view',
        'inboxes.manage',
        'conversations.view',
        'conversations.reply',
        'conversations.assign',
    ];

    private const TENANT_TABLES = [
        'notifications',
        'notification_preferences',
        'inboxes',
        'inbox_channels',
        'conversations',
        'conversation_participants',
        'messages',
        'message_attachments',
        'conversation_assignments',
        'conversation_reads',
        'canned_responses',
    ];

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('event', 120)->nullable()->after('type');
            $table->string('priority', 20)->default('normal')->after('event');
            $table->index(['tenant_id', 'notifiable_type', 'notifiable_id', 'read_at'], 'notifications_tenant_user_read_index');
            $table->index(['tenant_id', 'event', 'created_at'], 'notifications_tenant_event_index');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 120);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(true);
            $table->string('delivery', 20)->default('immediate');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'event', 'channel'], 'notification_preferences_unique');
            $table->index(['tenant_id', 'user_id', 'enabled'], 'notification_preferences_lookup_index');
        });

        Schema::create('inboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('default_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'inboxes_tenant_name_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inbox_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 30);
            $table->string('name', 120);
            $table->string('address', 190)->nullable();
            $table->string('external_identifier', 190)->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'inbox_id', 'channel', 'name'], 'inbox_channels_unique');
            $table->index(['tenant_id', 'channel', 'status'], 'inbox_channels_tenant_channel_index');
            $table->index(['tenant_id', 'external_identifier'], 'inbox_channels_external_index');
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('channel', 30);
            $table->string('subject', 255)->nullable();
            $table->string('external_identifier', 190)->nullable();
            $table->string('status', 30)->default('open');
            $table->string('priority', 20)->default('normal');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'inbox_channel_id', 'external_identifier'], 'conversations_external_unique');
            $table->index(['tenant_id', 'status', 'last_message_at'], 'conversations_status_activity_index');
            $table->index(['tenant_id', 'channel', 'last_message_at'], 'conversations_channel_activity_index');
            $table->index(['tenant_id', 'assigned_user_id', 'status'], 'conversations_owner_status_index');
            $table->index(['tenant_id', 'assigned_role_id', 'status'], 'conversations_role_status_index');
            $table->index(['tenant_id', 'contact_id', 'last_message_at'], 'conversations_contact_activity_index');
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('role', 30)->default('participant');
            $table->string('external_identifier', 190)->nullable();
            $table->string('display_name', 190)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'conversation_id', 'type'], 'conversation_participants_lookup_index');
            $table->index(['tenant_id', 'external_identifier'], 'conversation_participants_external_index');
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sender_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('direction', 20);
            $table->string('type', 30)->default('text');
            $table->string('sender_type', 30)->default('user');
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->json('content')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('queued');
            $table->string('client_message_id', 190)->nullable();
            $table->string('external_message_id', 190)->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'client_message_id'], 'messages_client_id_unique');
            $table->unique(['tenant_id', 'inbox_channel_id', 'external_message_id'], 'messages_external_id_unique');
            $table->index(['tenant_id', 'conversation_id', 'occurred_at'], 'messages_conversation_time_index');
            $table->index(['tenant_id', 'status', 'created_at'], 'messages_status_index');
        });

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_record_id')->nullable()->constrained('file_records')->nullOnDelete();
            $table->string('provider_attachment_id', 190)->nullable();
            $table->string('filename', 255);
            $table->string('mime_type', 190)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'message_id']);
        });

        Schema::create('conversation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'conversation_assignments_history_index');
        });

        Schema::create('conversation_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'conversation_id', 'user_id'], 'conversation_reads_unique');
        });

        Schema::create('canned_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->string('shortcut', 80);
            $table->text('body');
            $table->string('channel', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'shortcut'], 'canned_responses_shortcut_unique');
            $table->index(['tenant_id', 'active', 'channel']);
        });

        $this->backfillNotifications();
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

        Schema::dropIfExists('canned_responses');
        Schema::dropIfExists('conversation_reads');
        Schema::dropIfExists('conversation_assignments');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('inbox_channels');
        Schema::dropIfExists('inboxes');
        Schema::dropIfExists('notification_preferences');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_tenant_user_read_index');
            $table->dropIndex('notifications_tenant_event_index');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['event', 'priority']);
        });

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
    }

    private function backfillNotifications(): void
    {
        DB::table('notifications')->orderBy('created_at')->chunk(250, function ($notifications): void {
            foreach ($notifications as $notification) {
                $data = json_decode((string) $notification->data, true);
                $tenantIds = DB::table('tenant_user')
                    ->where('user_id', $notification->notifiable_id)
                    ->where('status', 'active')
                    ->pluck('tenant_id');
                DB::table('notifications')->where('id', $notification->id)->update([
                    'tenant_id' => $tenantIds->count() === 1 ? $tenantIds->first() : null,
                    'event' => is_array($data) ? ($data['event'] ?? $data['type'] ?? null) : null,
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
            'inbox_channels' => ['settings'],
            'messages' => ['content', 'metadata'],
            'message_attachments' => ['metadata'],
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
