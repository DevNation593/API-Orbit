<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'email_accounts.view', 'email_accounts.manage',
        'email_templates.view', 'email_templates.manage',
        'whatsapp.view', 'whatsapp.manage',
    ];

    private const TENANT_TABLES = [
        'email_accounts', 'email_templates', 'email_tracking_links', 'email_tracking_events',
        'whatsapp_accounts', 'whatsapp_templates', 'channel_webhook_events',
    ];

    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('email_address', 190);
            $table->string('display_name', 120)->nullable();
            $table->text('signature_html')->nullable();
            $table->json('settings')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'inbox_channel_id'], 'email_accounts_channel_unique');
            $table->unique(['tenant_id', 'provider', 'email_address'], 'email_accounts_address_unique');
            $table->index(['tenant_id', 'status', 'last_synced_at'], 'email_accounts_status_sync_index');
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('subject', 255);
            $table->text('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'email_templates_tenant_name_unique');
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('email_tracking_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('destination_url')->nullable();
            $table->string('kind', 20);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'message_id', 'kind'], 'email_tracking_links_message_index');
        });

        Schema::create('email_tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('tracking_link_id')->nullable()->constrained('email_tracking_links')->nullOnDelete();
            $table->string('event', 30);
            $table->string('url_hash', 64)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'message_id', 'event', 'occurred_at'], 'email_tracking_events_lookup_index');
        });

        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('business_account_id', 190)->nullable();
            $table->string('phone_number_id', 190)->unique();
            $table->string('display_phone_number', 80)->nullable();
            $table->char('verify_token_hash', 64)->unique();
            $table->json('settings')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'inbox_channel_id'], 'whatsapp_accounts_channel_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 190)->nullable();
            $table->string('name', 190);
            $table->string('language', 20);
            $table->string('category', 50)->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('components')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'whatsapp_account_id', 'name', 'language'], 'whatsapp_templates_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('channel_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->unsignedBigInteger('account_id');
            $table->string('event_type', 80);
            $table->string('dedupe_key', 190);
            $table->text('payload');
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'account_id', 'dedupe_key'], 'channel_webhook_events_unique');
            $table->index(['tenant_id', 'provider', 'status', 'created_at'], 'channel_webhook_events_status_index');
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

        Schema::dropIfExists('channel_webhook_events');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_accounts');
        Schema::dropIfExists('email_tracking_events');
        Schema::dropIfExists('email_tracking_links');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_accounts');

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
                    'permission_id' => $permissionId, 'role_id' => $roleId,
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
            'email_accounts' => ['settings'],
            'email_templates' => ['variables'],
            'whatsapp_accounts' => ['settings'],
            'whatsapp_templates' => ['components'],
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
