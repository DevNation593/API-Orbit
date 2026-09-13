<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\KnowledgeTestCase;

class KnowledgeConfigurationTest extends KnowledgeTestCase
{
    use RefreshDatabase;

    public function test_it_configures_one_base_and_creates_catalogs_without_slugs(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])
            ->withHeader('X-Tenant-ID', $client['tenant']->id);

        $base = $api->putJson('/api/v1/knowledge/settings', [
            'title' => 'Centro de ayuda',
            'description' => 'Respuestas verificadas',
            'is_public' => true,
        ])->assertCreated();

        $base->assertJsonPath('data.title', 'Centro de ayuda')
            ->assertJsonPath('data.is_public', true);
        $this->assertTrue(Str::isUuid($base->json('data.public_id')));

        $categoryId = $api->postJson('/api/v1/knowledge/categories', [
            'name' => 'Facturación',
            'position' => 10,
        ])->assertCreated()->json('data.id');

        $tagId = $api->postJson('/api/v1/knowledge/tags', [
            'name' => 'Primeros pasos',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('knowledge_categories', [
            'id' => $categoryId,
            'tenant_id' => $client['tenant']->id,
        ]);
        $this->assertDatabaseHas('knowledge_tags', [
            'id' => $tagId,
            'tenant_id' => $client['tenant']->id,
        ]);
        $this->assertFalse(Schema::hasColumn('knowledge_articles', 'slug'));
    }

    public function test_settings_put_is_idempotent_and_preserves_its_server_generated_public_id(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);

        $first = $api->putJson('/api/v1/knowledge/settings', [
            'title' => 'Centro de ayuda',
            'is_public' => false,
        ])->assertCreated();
        $publicId = $first->json('data.public_id');

        $api->putJson('/api/v1/knowledge/settings', [
            'title' => 'Ayuda actualizada',
            'description' => null,
            'is_public' => true,
        ])->assertOk()
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.title', 'Ayuda actualizada');

        $this->assertTrue(Str::isUuid($publicId));
        $this->assertDatabaseCount('knowledge_bases', 1);
    }

    public function test_read_and_manage_permissions_are_enforced_separately(): void
    {
        $manager = $this->knowledgeFixture(['knowledge.manage']);
        $this->withToken($manager['token'])->withHeader('X-Tenant-ID', $manager['tenant']->id)
            ->getJson('/api/v1/knowledge/settings')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $viewer = $this->knowledgeFixture(['knowledge.view']);
        $api = $this->withToken($viewer['token'])->withHeader('X-Tenant-ID', $viewer['tenant']->id);
        $api->getJson('/api/v1/knowledge/settings')->assertOk();
        $api->putJson('/api/v1/knowledge/settings', ['title' => 'Denied'])->assertForbidden();
        $api->postJson('/api/v1/knowledge/categories', ['name' => 'Denied'])->assertForbidden();
        $api->postJson('/api/v1/knowledge/tags', ['name' => 'Denied'])->assertForbidden();
        $api->patchJson('/api/v1/knowledge/categories/'.$viewer['category']->id, ['name' => 'Denied'])->assertForbidden();
        $api->deleteJson('/api/v1/knowledge/tags/'.$viewer['tag']->id)->assertForbidden();
    }

    public function test_equivalent_normalized_catalog_names_conflict_within_the_tenant(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);

        $category = $api->postJson('/api/v1/knowledge/categories', ['name' => 'Facturación'])->assertCreated();
        $api->postJson('/api/v1/knowledge/categories', ['name' => '  FACTURACION!!!  '])
            ->assertConflict()->assertJsonMissingPath('errors.normalized_name');

        $tag = $api->postJson('/api/v1/knowledge/tags', ['name' => 'Primeros pasos'])->assertCreated();
        $api->postJson('/api/v1/knowledge/tags', ['name' => ' primeros---PASOS '])
            ->assertConflict()->assertJsonMissingPath('errors.normalized_name');

        $this->assertSame('Facturación', $category->json('data.name'));
        $this->assertSame('Primeros pasos', $tag->json('data.name'));
        $this->assertDatabaseCount('knowledge_categories', 1);
        $this->assertDatabaseCount('knowledge_tags', 1);
    }

    public function test_server_controlled_identifiers_and_metadata_are_rejected_by_every_request(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $requests = [
            ['put', '/api/v1/knowledge/settings', ['title' => 'Centro']],
            ['post', '/api/v1/knowledge/categories', ['name' => 'Facturación']],
            ['post', '/api/v1/knowledge/tags', ['name' => 'Inicio']],
        ];
        $forbidden = [
            'id' => 99,
            'tenant_id' => 99,
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'normalized_name' => 'controlled',
            'created_by' => 99,
            'updated_by' => 99,
            'author_id' => 99,
            'status' => 'PUBLISHED',
            'current_version_id' => 99,
            'published_version_id' => 99,
            'published_at' => '2026-09-12 00:00:00',
            'archived_at' => '2026-09-12 00:00:00',
            'created_at' => '2026-09-12 00:00:00',
            'updated_at' => '2026-09-12 00:00:00',
            'slug' => 'readable-id',
        ];

        foreach ($requests as [$method, $uri, $valid]) {
            foreach ($forbidden as $field => $value) {
                $api->json($method, $uri, $valid + [$field => $value])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
    }

    public function test_base_upsert_retries_a_tenant_unique_race(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $injected = false;

        KnowledgeBase::creating(function (KnowledgeBase $base) use (&$injected, $client): void {
            if ($injected || $base->title !== 'Race-safe base') {
                return;
            }

            $injected = true;
            DB::table('knowledge_bases')->insert([
                'tenant_id' => $client['tenant']->id,
                'public_id' => (string) Str::uuid(),
                'title' => 'Concurrent base',
                'description' => null,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $api->putJson('/api/v1/knowledge/settings', [
            'title' => 'Race-safe base',
            'is_public' => true,
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Race-safe base')
            ->assertJsonPath('data.is_public', true);

        $this->assertTrue($injected);
        $this->assertDatabaseCount('knowledge_bases', 1);
    }

    public function test_category_unique_races_are_mapped_to_conflict(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $injected = false;

        KnowledgeCategory::creating(function (KnowledgeCategory $category) use (&$injected, $client): void {
            if ($injected || $category->name !== 'Race category') {
                return;
            }

            $injected = true;
            DB::table('knowledge_categories')->insert([
                'tenant_id' => $client['tenant']->id,
                'created_by' => $client['user']->id,
                'name' => 'Concurrent category',
                'normalized_name' => $category->normalized_name,
                'description' => null,
                'position' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $api->postJson('/api/v1/knowledge/categories', ['name' => 'Race category'])
            ->assertConflict();

        $this->assertTrue($injected);
        $this->assertDatabaseCount('knowledge_categories', 0);
    }

    public function test_tag_unique_races_are_mapped_to_conflict(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $injected = false;

        KnowledgeTag::creating(function (KnowledgeTag $tag) use (&$injected, $client): void {
            if ($injected || $tag->name !== 'Race tag') {
                return;
            }

            $injected = true;
            DB::table('knowledge_tags')->insert([
                'tenant_id' => $client['tenant']->id,
                'created_by' => $client['user']->id,
                'name' => 'Concurrent tag',
                'normalized_name' => $tag->normalized_name,
                'description' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $api->postJson('/api/v1/knowledge/tags', ['name' => 'Race tag'])
            ->assertConflict();

        $this->assertTrue($injected);
        $this->assertDatabaseCount('knowledge_tags', 0);
    }

    public function test_catalog_queries_escape_like_wildcards_and_validate_filters(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach ([
            ['categories', 'Percent % literal'],
            ['categories', 'Under_score literal'],
            ['categories', 'Bang ! literal'],
            ['tags', 'Percent % literal'],
            ['tags', 'Under_score literal'],
            ['tags', 'Bang ! literal'],
        ] as [$resource, $name]) {
            $api->postJson('/api/v1/knowledge/'.$resource, ['name' => $name])->assertCreated();
        }

        foreach (['categories', 'tags'] as $resource) {
            $api->getJson('/api/v1/knowledge/'.$resource.'?q=%25&active=1&per_page=1')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Percent % literal')
                ->assertJsonPath('meta.per_page', 1);
            $api->getJson('/api/v1/knowledge/'.$resource.'?q=_')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Under_score literal');
            $api->getJson('/api/v1/knowledge/'.$resource.'?q=!')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bang ! literal');
            $api->getJson('/api/v1/knowledge/'.$resource.'?per_page=101')->assertUnprocessable();
            $api->getJson('/api/v1/knowledge/'.$resource.'?q='.str_repeat('x', 121))->assertUnprocessable();
        }
    }

    public function test_catalog_crud_is_tenant_scoped_and_foreign_route_resources_are_hidden(): void
    {
        $foreign = $this->knowledgeFixture();
        $this->app['auth']->forgetGuards();
        $client = $this->knowledgeFixture();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);

        foreach (['categories' => $foreign['category']->id, 'tags' => $foreign['tag']->id] as $resource => $id) {
            $api->getJson('/api/v1/knowledge/'.$resource.'/'.$id)->assertNotFound();
            $api->patchJson('/api/v1/knowledge/'.$resource.'/'.$id, ['name' => 'Hidden'])->assertNotFound();
            $api->deleteJson('/api/v1/knowledge/'.$resource.'/'.$id)->assertNotFound();
        }

        $api->patchJson('/api/v1/knowledge/categories/'.$client['category']->id, [
            'name' => 'Cobros', 'position' => 20, 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Cobros')->assertJsonPath('data.position', 20);
        $api->patchJson('/api/v1/knowledge/tags/'.$client['tag']->id, [
            'name' => 'Inicio', 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Inicio')->assertJsonPath('data.is_active', false);
        $api->getJson('/api/v1/knowledge/categories?active=0')->assertOk()->assertJsonCount(1, 'data');
        $api->getJson('/api/v1/knowledge/tags?active=0')->assertOk()->assertJsonCount(1, 'data');
        $api->deleteJson('/api/v1/knowledge/categories/'.$client['category']->id)->assertOk();
        $api->deleteJson('/api/v1/knowledge/tags/'.$client['tag']->id)->assertOk();
    }

    public function test_errors_and_audits_do_not_leak_private_references_or_role_names(): void
    {
        $foreign = $this->knowledgeFixture();
        $foreign['category']->update(['description' => 'SECRET_FOREIGN_REFERENCE']);
        $foreign['user']->memberships()->first()->role->update(['name' => 'SECRET_FOREIGN_ROLE']);

        $this->app['auth']->forgetGuards();
        $viewer = $this->knowledgeFixture(['knowledge.view']);
        $response = $this->withToken($viewer['token'])->withHeader('X-Tenant-ID', $viewer['tenant']->id)
            ->getJson('/api/v1/knowledge/categories/'.$foreign['category']->id)->assertNotFound();
        $this->assertStringNotContainsString('SECRET_FOREIGN_REFERENCE', $response->getContent());
        $this->assertStringNotContainsString('SECRET_FOREIGN_ROLE', $response->getContent());

        $denied = $this->withToken($viewer['token'])->withHeader('X-Tenant-ID', $viewer['tenant']->id)
            ->postJson('/api/v1/knowledge/categories', ['name' => 'Denied'])->assertForbidden();
        $this->assertStringNotContainsString('SECRET_FOREIGN_ROLE', $denied->getContent());

        $this->app['auth']->forgetGuards();
        $manager = $this->knowledgeFixture();
        $api = $this->withToken($manager['token'])->withHeader('X-Tenant-ID', $manager['tenant']->id);
        $api->postJson('/api/v1/knowledge/categories', [
            'name' => 'Private category', 'description' => 'SECRET_CATEGORY_DESCRIPTION',
        ])->assertCreated();
        $api->postJson('/api/v1/knowledge/tags', [
            'name' => 'Private tag', 'description' => 'SECRET_TAG_DESCRIPTION',
        ])->assertCreated();
        $audit = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('SECRET_CATEGORY_DESCRIPTION', $audit);
        $this->assertStringNotContainsString('SECRET_TAG_DESCRIPTION', $audit);
    }

    public function test_referenced_catalogs_cannot_be_deleted_and_model_relations_are_tenant_safe(): void
    {
        $client = $this->knowledgeFixture();
        $article = KnowledgeArticle::create([
            'public_id' => (string) Str::uuid(),
            'created_by' => $client['user']->id,
            'updated_by' => $client['user']->id,
        ]);
        $version = KnowledgeArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'category_id' => $client['category']->id,
            'author_id' => $client['user']->id,
            'title' => 'Primera versión',
            'body_html' => '<p>Contenido</p>',
            'visibility' => 'PUBLIC',
        ]);
        $version->tags()->attach($client['tag']->id, ['tenant_id' => $client['tenant']->id]);
        $article->update(['current_version_id' => $version->id, 'published_version_id' => $version->id]);

        $this->assertSame($client['tenant']->id, $client['base']->tenant->id);
        $this->assertSame($version->id, $article->fresh()->currentVersion->id);
        $this->assertSame($version->id, $article->fresh()->publishedVersion->id);
        $this->assertSame([1], $article->versions->pluck('version')->all());
        $this->assertSame($article->id, $version->article->id);
        $this->assertSame($client['category']->id, $version->category->id);
        $this->assertSame($client['user']->id, $version->author->id);
        $this->assertSame($client['tag']->id, $version->tags->sole()->id);
        $this->assertSame($version->id, $client['category']->versions->sole()->id);
        $this->assertSame($version->id, $client['tag']->versions->sole()->id);
        $this->assertArrayNotHasKey('normalized_name', $client['category']->toArray());
        $this->assertArrayNotHasKey('normalized_name', $client['tag']->toArray());

        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->deleteJson('/api/v1/knowledge/categories/'.$client['category']->id)->assertConflict();
        $api->deleteJson('/api/v1/knowledge/tags/'.$client['tag']->id)->assertConflict();
    }

    public function test_migration_grants_only_system_and_settings_manager_roles(): void
    {
        $manager = $this->createTenantUser(['settings.manage']);
        $custom = $this->createTenantUser(['contacts.view']);
        $system = Role::create(['tenant_id' => $manager['tenant']->id, 'name' => 'System', 'is_system' => true]);
        $migration = require database_path('migrations/2026_09_12_000000_create_knowledge_base_foundations.php');

        $migration->down();
        $this->assertSame(0, Permission::where('key', 'knowledge.view')->count());
        $migration->up();

        $keys = ['knowledge.view', 'knowledge.manage', 'knowledge.publish'];
        $this->assertSame(3, $system->permissions()->whereIn('key', $keys)->count());
        $this->assertSame(3, $manager['user']->memberships()->first()->role->permissions()->whereIn('key', $keys)->count());
        $this->assertSame(0, $custom['user']->memberships()->first()->role->permissions()->whereIn('key', $keys)->count());
        $this->assertTrue($custom['user']->hasPermission('contacts.view', $custom['tenant']->id));
    }

    public function test_knowledge_migration_can_be_reversed_and_reapplied_in_memory(): void
    {
        $migration = require database_path('migrations/2026_09_12_000000_create_knowledge_base_foundations.php');
        $tables = [
            'knowledge_bases', 'knowledge_categories', 'knowledge_tags', 'knowledge_articles',
            'knowledge_article_versions', 'knowledge_article_version_tags',
        ];

        $migration->down();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['tenant_id', 'created_at', 'updated_at']));
        }
        $this->assertFalse(Schema::hasColumn('knowledge_articles', 'slug'));
    }
}
