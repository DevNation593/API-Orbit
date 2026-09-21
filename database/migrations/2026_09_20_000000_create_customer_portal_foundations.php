<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['portal.manage'];

    private const TENANT_TABLES = [
        'customer_portals',
        'portal_users',
        'portal_invitations',
        'portal_password_reset_tokens',
    ];

    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'contacts_id_tenant_unique');
        });

        Schema::create('customer_portals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('title', 120);
            $table->boolean('is_active')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique('tenant_id', 'customer_portals_tenant_unique');
            $table->unique(['id', 'tenant_id'], 'customer_portals_id_tenant_unique');
        });

        Schema::create('portal_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->string('email', 190);
            $table->string('password');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'tenant_id'], 'portal_users_id_tenant_unique');
            $table->unique(['tenant_id', 'contact_id'], 'portal_users_contact_unique');
            $table->unique(['tenant_id', 'email'], 'portal_users_email_unique');
            $table->index(['tenant_id', 'status', 'last_login_at'], 'portal_users_list_index');
            $table->foreign(['contact_id', 'tenant_id'], 'portal_users_contact_tenant_fk')
                ->references(['id', 'tenant_id'])->on('contacts')->restrictOnDelete();
        });

        Schema::create('portal_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'tenant_id'], 'portal_invitations_id_tenant_unique');
            $table->index(['tenant_id', 'contact_id', 'status'], 'portal_invitations_contact_index');
            $table->index(['tenant_id', 'status', 'expires_at'], 'portal_invitations_expiry_index');
            $table->foreign(['contact_id', 'tenant_id'], 'portal_invitations_contact_tenant_fk')
                ->references(['id', 'tenant_id'])->on('contacts')->restrictOnDelete();
        });

        Schema::create('portal_password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('portal_user_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(
                ['tenant_id', 'portal_user_id', 'used_at', 'expires_at'],
                'portal_password_resets_lookup_index',
            );
            $table->foreign(['portal_user_id', 'tenant_id'], 'portal_resets_user_tenant_fk')
                ->references(['id', 'tenant_id'])->on('portal_users')->cascadeOnDelete();
        });

        Schema::create('customer_portal_locators', function (Blueprint $table): void {
            $table->uuid('public_id')->primary();
            $table->unsignedBigInteger('portal_id')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->timestamps();
            $table->foreign(['portal_id', 'tenant_id'], 'portal_locators_portal_tenant_fk')
                ->references(['id', 'tenant_id'])->on('customer_portals')->cascadeOnDelete();
        });

        Schema::create('portal_invitation_locators', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->unsignedBigInteger('invitation_id')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->timestamps();
            $table->foreign(['invitation_id', 'tenant_id'], 'invitation_locators_invitation_tenant_fk')
                ->references(['id', 'tenant_id'])->on('portal_invitations')->cascadeOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('portal_user_id')->nullable()
                ->constrained('portal_users')->nullOnDelete();
            $table->index(
                ['tenant_id', 'portal_user_id', 'created_at'],
                'audit_logs_portal_actor_index',
            );
        });

        $this->installPermissions();
        $this->enableRls();
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_logs', 'portal_user_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                if (DB::getDriverName() === 'sqlite') {
                    $table->dropForeign(['portal_user_id']);
                } else {
                    $table->dropForeign('audit_logs_portal_user_id_foreign');
                }
            });
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropIndex('audit_logs_portal_actor_index');
            });
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropColumn('portal_user_id');
            });
        }

        Schema::dropIfExists('portal_invitation_locators');
        Schema::dropIfExists('customer_portal_locators');
        Schema::dropIfExists('portal_password_reset_tokens');
        Schema::dropIfExists('portal_invitations');
        Schema::dropIfExists('portal_users');
        Schema::dropIfExists('customer_portals');

        $permissionIds = DB::table('permissions')
            ->whereIn('key', self::PERMISSIONS)
            ->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique('contacts_id_tenant_unique');
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

        $permissionIds = DB::table('permissions')
            ->whereIn('key', self::PERMISSIONS)
            ->pluck('id');
        $roleIds = DB::table('roles')->where('is_system', true)
            ->orWhereIn('id', function ($query): void {
                $query->select('permission_role.role_id')
                    ->from('permission_role')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permissions.key', 'settings.manage');
            })->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    private function enableRls(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled')) {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }
};
