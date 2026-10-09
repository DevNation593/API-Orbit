<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'search.view',
        'saved_views.view',
        'saved_views.manage',
        'tags.view',
        'tags.manage',
        'duplicates.manage',
    ];

    private const TENANT_TABLES = [
        'saved_views',
        'saved_view_filters',
        'tags',
        'tag_assignments',
        'record_merges',
    ];

    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('email_normalized')->nullable();
            $table->string('phone_normalized', 32)->nullable();
            $table->string('name_normalized')->nullable();
            $table->string('identification_normalized')->nullable();
            $table->string('tax_id_normalized')->nullable();
            $table->index(['tenant_id', 'email_normalized'], 'contacts_tenant_email_normalized_index');
            $table->index(['tenant_id', 'phone_normalized'], 'contacts_tenant_phone_normalized_index');
            $table->index(['tenant_id', 'name_normalized'], 'contacts_tenant_name_normalized_index');
            $table->index(['tenant_id', 'identification_normalized'], 'contacts_tenant_identification_normalized_index');
            $table->index(['tenant_id', 'tax_id_normalized'], 'contacts_tenant_tax_id_normalized_index');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('email_normalized')->nullable();
            $table->string('phone_normalized', 32)->nullable();
            $table->string('name_normalized')->nullable();
            $table->string('website_normalized')->nullable();
            $table->string('identification_normalized')->nullable();
            $table->string('tax_id_normalized')->nullable();
            $table->index(['tenant_id', 'email_normalized'], 'organizations_tenant_email_normalized_index');
            $table->index(['tenant_id', 'phone_normalized'], 'organizations_tenant_phone_normalized_index');
            $table->index(['tenant_id', 'name_normalized'], 'organizations_tenant_name_normalized_index');
            $table->index(['tenant_id', 'website_normalized'], 'organizations_tenant_website_normalized_index');
            $table->index(['tenant_id', 'identification_normalized'], 'organizations_tenant_identification_normalized_index');
            $table->index(['tenant_id', 'tax_id_normalized'], 'organizations_tenant_tax_id_normalized_index');
        });

        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_with_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('entity_type', 80);
            $table->string('name', 120);
            $table->string('visibility', 20)->default('private');
            $table->string('sort_field', 120)->nullable();
            $table->string('sort_direction', 4)->nullable();
            $table->json('columns')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'entity_type', 'name'], 'saved_views_owner_entity_name_unique');
            $table->index(['tenant_id', 'entity_type', 'visibility'], 'saved_views_tenant_entity_visibility_index');
            $table->index(['tenant_id', 'shared_with_role_id'], 'saved_views_tenant_role_index');
        });

        Schema::create('saved_view_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_view_id')->constrained()->cascadeOnDelete();
            $table->string('field', 120);
            $table->string('operator', 30);
            $table->json('value')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'saved_view_id', 'position'], 'saved_view_filters_lookup_index');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->char('color', 7)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_name'], 'tags_tenant_normalized_name_unique');
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('tag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('taggable_type', 80);
            $table->unsignedBigInteger('taggable_id');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'tag_id', 'taggable_type', 'taggable_id'], 'tag_assignments_unique');
            $table->index(['tenant_id', 'taggable_type', 'taggable_id'], 'tag_assignments_record_index');
        });

        Schema::create('record_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('source_snapshot')->nullable();
            $table->json('moved_relations')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'entity_type', 'source_id'], 'record_merges_source_unique');
            $table->index(['tenant_id', 'entity_type', 'target_id'], 'record_merges_target_index');
        });

        $this->backfillNormalizedValues();
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

        Schema::dropIfExists('record_merges');
        Schema::dropIfExists('tag_assignments');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('saved_view_filters');
        Schema::dropIfExists('saved_views');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('organizations_tenant_email_normalized_index');
            $table->dropIndex('organizations_tenant_phone_normalized_index');
            $table->dropIndex('organizations_tenant_name_normalized_index');
            $table->dropIndex('organizations_tenant_website_normalized_index');
            $table->dropIndex('organizations_tenant_identification_normalized_index');
            $table->dropIndex('organizations_tenant_tax_id_normalized_index');
            $table->dropColumn([
                'email_normalized', 'phone_normalized', 'name_normalized', 'website_normalized',
                'identification_normalized', 'tax_id_normalized',
            ]);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_tenant_email_normalized_index');
            $table->dropIndex('contacts_tenant_phone_normalized_index');
            $table->dropIndex('contacts_tenant_name_normalized_index');
            $table->dropIndex('contacts_tenant_identification_normalized_index');
            $table->dropIndex('contacts_tenant_tax_id_normalized_index');
            $table->dropColumn([
                'email_normalized', 'phone_normalized', 'name_normalized',
                'identification_normalized', 'tax_id_normalized',
            ]);
        });

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
    }

    private function backfillNormalizedValues(): void
    {
        DB::table('contacts')->orderBy('id')->chunkById(250, function ($contacts): void {
            foreach ($contacts as $contact) {
                $custom = $this->decodeJson($contact->custom_fields ?? null);
                DB::table('contacts')->where('id', $contact->id)->update([
                    'email_normalized' => $this->email($contact->email ?? null),
                    'phone_normalized' => $this->phone($contact->phone ?? null),
                    'name_normalized' => $this->text(trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''))),
                    'identification_normalized' => $this->identifier($custom['identification'] ?? null),
                    'tax_id_normalized' => $this->identifier($custom['tax_id'] ?? null),
                ]);
            }
        });

        DB::table('organizations')->orderBy('id')->chunkById(250, function ($organizations): void {
            foreach ($organizations as $organization) {
                $custom = $this->decodeJson($organization->custom_fields ?? null);
                DB::table('organizations')->where('id', $organization->id)->update([
                    'email_normalized' => $this->email($organization->email ?? null),
                    'phone_normalized' => $this->phone($organization->phone ?? null),
                    'name_normalized' => $this->text($organization->name ?? null),
                    'website_normalized' => $this->website($organization->website ?? null),
                    'identification_normalized' => $this->identifier($custom['identification'] ?? null),
                    'tax_id_normalized' => $this->identifier($custom['tax_id'] ?? null),
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
        $administrativeRoleIds = DB::table('roles')
            ->where('is_system', true)
            ->orWhereIn('id', function ($query): void {
                $query->select('permission_role.role_id')
                    ->from('permission_role')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permissions.key', 'settings.manage');
            })
            ->pluck('id');

        foreach ($administrativeRoleIds as $roleId) {
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
            'saved_views' => ['columns'],
            'saved_view_filters' => ['value'],
            'record_merges' => ['source_snapshot', 'moved_relations'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
            }
        }

        if (! config('tenancy.rls_enabled')) {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            $policy = $table.'_tenant_isolation';
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$policy} ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function email(mixed $value): ?string
    {
        return $this->nullable(mb_strtolower(trim((string) $value)));
    }

    private function phone(mixed $value): ?string
    {
        return $this->nullable(preg_replace('/\D+/', '', (string) $value));
    }

    private function identifier(mixed $value): ?string
    {
        return $this->nullable(mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', '', trim((string) $value))));
    }

    private function text(mixed $value): ?string
    {
        $value = Str::lower(Str::ascii(trim((string) $value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return $this->nullable(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function website(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $host = parse_url($raw, PHP_URL_HOST) ?: parse_url('https://'.$raw, PHP_URL_HOST);
        $host = mb_strtolower((string) $host);

        return $this->nullable(preg_replace('/^www\./', '', rtrim($host, '.')));
    }

    private function nullable(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
};
