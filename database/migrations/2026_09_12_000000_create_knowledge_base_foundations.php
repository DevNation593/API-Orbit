<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'knowledge.view',
        'knowledge.manage',
        'knowledge.publish',
    ];

    private const TABLES = [
        'knowledge_bases',
        'knowledge_categories',
        'knowledge_tags',
        'knowledge_articles',
        'knowledge_article_versions',
        'knowledge_article_version_tags',
    ];

    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->unique('tenant_id', 'knowledge_bases_tenant_unique');
        });

        Schema::create('knowledge_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_name'], 'knowledge_categories_name_unique');
            $table->index(['tenant_id', 'is_active', 'position'], 'knowledge_categories_list_index');
        });

        Schema::create('knowledge_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_name'], 'knowledge_tags_name_unique');
            $table->index(['tenant_id', 'is_active', 'name'], 'knowledge_tags_list_index');
        });

        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'updated_at'], 'knowledge_articles_status_index');
            $table->index(['tenant_id', 'published_at'], 'knowledge_articles_published_index');
        });

        Schema::create('knowledge_article_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('knowledge_articles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('category_id')->nullable()->constrained('knowledge_categories')->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->longText('body_html');
            $table->string('visibility', 20);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->string('change_summary', 500)->nullable();
            $table->timestamps();
            $table->unique(['article_id', 'version'], 'knowledge_article_version_unique');
            $table->index(['tenant_id', 'category_id', 'visibility'], 'knowledge_versions_catalog_index');
        });

        Schema::create('knowledge_article_version_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('knowledge_article_versions')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('knowledge_tags')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['version_id', 'tag_id'], 'knowledge_version_tag_unique');
            $table->index(['tenant_id', 'tag_id', 'version_id'], 'knowledge_version_tag_lookup_index');
        });

        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'knowledge_articles_current_version_fk')
                ->references('id')->on('knowledge_article_versions')->nullOnDelete();
            $table->foreign('published_version_id', 'knowledge_articles_published_version_fk')
                ->references('id')->on('knowledge_article_versions')->nullOnDelete();
        });

        $this->installPermissions();
        $this->enableRls();
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_articles')) {
            Schema::table('knowledge_articles', function (Blueprint $table): void {
                if (DB::getDriverName() === 'sqlite') {
                    $table->dropForeign(['current_version_id']);
                    $table->dropForeign(['published_version_id']);
                } else {
                    $table->dropForeign('knowledge_articles_current_version_fk');
                    $table->dropForeign('knowledge_articles_published_version_fk');
                }
            });
        }

        Schema::dropIfExists('knowledge_article_version_tags');
        Schema::dropIfExists('knowledge_article_versions');
        Schema::dropIfExists('knowledge_articles');
        Schema::dropIfExists('knowledge_tags');
        Schema::dropIfExists('knowledge_categories');
        Schema::dropIfExists('knowledge_bases');

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
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

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }
};
