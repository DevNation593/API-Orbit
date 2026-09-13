<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use App\Services\KnowledgeArticleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\KnowledgeTestCase;

class KnowledgeArticleVersioningTest extends KnowledgeTestCase
{
    use RefreshDatabase;

    public function test_patch_creates_an_immutable_version_and_rejects_a_stale_editor(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);

        $article = $this->postJson('/api/v1/knowledge/articles', [
            'title' => 'Configurar pagos',
            'summary' => 'Guía inicial',
            'body_html' => '<p>Versión uno</p><script>alert(1)</script>',
            'visibility' => 'PUBLIC',
            'category_id' => $client['category']->id,
            'tag_ids' => [$client['tag']->id],
        ])->assertCreated();

        $id = $article->json('data.id');
        $article->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.current_version.version', 1);
        $this->assertStringNotContainsString(
            '<script',
            (string) $article->json('data.current_version.body_html')
        );

        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'body_html' => '<p>Versión dos</p>',
            'change_summary' => 'Aclaración',
        ])->assertOk()->assertJsonPath('data.current_version.version', 2);

        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'title' => 'Edición obsoleta',
        ])->assertConflict();

        $this->assertDatabaseHas('knowledge_article_versions', [
            'article_id' => $id,
            'version' => 1,
            'body_html' => '<p>Versión uno</p>',
        ]);
        $this->assertDatabaseHas('knowledge_article_versions', [
            'article_id' => $id,
            'version' => 2,
            'body_html' => '<p>Versión dos</p>',
        ]);
        $this->assertDatabaseCount('knowledge_articles', 1);
        $this->assertDatabaseCount('knowledge_article_versions', 2);
        $this->assertDatabaseCount('knowledge_article_version_tags', 2);
        $this->assertDatabaseMissing('knowledge_article_versions', [
            'body_html' => '<p>Versión uno</p><script>alert(1)</script>',
        ]);

        $audit = AuditLog::query()->whereIn('action', [
            'knowledge.article.created',
            'knowledge.article.revised',
        ])->get()->toJson();
        $this->assertStringNotContainsString('Versión uno', $audit);
        $this->assertStringNotContainsString('Versión dos', $audit);
        $this->assertStringNotContainsString('body_html', $audit);
    }

    public function test_creation_requires_configured_knowledge_settings(): void
    {
        $client = $this->createTenantUser();
        $this->authenticateKnowledge($client);

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Configure the knowledge base before creating articles.');

        $this->assertDatabaseCount('knowledge_articles', 0);
        $this->assertDatabaseCount('knowledge_article_versions', 0);
    }

    public function test_category_and_tag_references_must_be_active_and_belong_to_the_tenant(): void
    {
        $client = $this->knowledgeFixture();
        $client['category']->update(['is_active' => false]);
        $client['tag']->update(['is_active' => false]);

        $foreign = $this->knowledgeFixture();
        $foreign['category']->update(['name' => 'SECRET FOREIGN CATEGORY']);
        $foreign['tag']->update(['name' => 'SECRET FOREIGN TAG']);

        app(TenantContext::class)->set((int) $client['tenant']->id);
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($client);

        foreach ([$client['category']->id, $foreign['category']->id] as $categoryId) {
            $response = $this->postJson('/api/v1/knowledge/articles', $this->articlePayload([
                'category_id' => $categoryId,
            ]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
            $this->assertStringNotContainsString('SECRET FOREIGN CATEGORY', $response->getContent());
        }

        foreach ([$client['tag']->id, $foreign['tag']->id] as $tagId) {
            $response = $this->postJson('/api/v1/knowledge/articles', $this->articlePayload([
                'tag_ids' => [$tagId],
            ]))->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
            $this->assertStringNotContainsString('SECRET FOREIGN TAG', $response->getContent());
        }

        $this->assertDatabaseCount('knowledge_articles', 0);
    }

    public function test_tag_limit_and_empty_sanitized_html_are_rejected(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload([
            'tag_ids' => range(1, 21),
        ]))->assertUnprocessable()->assertJsonValidationErrors('tag_ids');

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload([
            'body_html' => '<script>alert(1)</script>',
        ]))->assertUnprocessable()->assertJsonValidationErrors('body_html');

        $this->assertDatabaseCount('knowledge_articles', 0);
        $this->assertDatabaseCount('knowledge_article_versions', 0);
    }

    public function test_manage_permission_is_required_to_create_or_revise_articles(): void
    {
        $viewer = $this->knowledgeFixture(['knowledge.view']);
        $this->authenticateKnowledge($viewer);

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload())
            ->assertForbidden();

        $manager = $this->knowledgeFixture();
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($manager);
        $id = $this->postJson('/api/v1/knowledge/articles', $this->articlePayload())
            ->assertCreated()->json('data.id');

        $manager['user']->memberships()->first()->role->permissions()->detach();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'title' => 'Denied',
        ])->assertForbidden();
    }

    public function test_server_controlled_fields_slug_and_empty_patch_are_rejected(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $controlled = [
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

        foreach ($controlled as $field => $value) {
            $this->postJson('/api/v1/knowledge/articles', $this->articlePayload([$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $id = $this->postJson('/api/v1/knowledge/articles', $this->articlePayload())
            ->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/knowledge/articles/'.$id, ['expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    }

    public function test_article_detail_is_tenant_scoped_and_listing_never_exposes_html(): void
    {
        $first = $this->knowledgeFixture();
        $this->authenticateKnowledge($first);
        $id = $this->postJson('/api/v1/knowledge/articles', $this->articlePayload([
            'body_html' => '<p>PRIVATE KNOWLEDGE BODY</p>',
        ]))->assertCreated()->json('data.id');

        $list = $this->getJson('/api/v1/knowledge/articles')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('PRIVATE KNOWLEDGE BODY', $list->getContent());
        $list->assertJsonMissingPath('data.0.current_version.body_html');
        $this->getJson('/api/v1/knowledge/articles/'.$id)->assertOk()
            ->assertJsonPath('data.current_version.body_html', '<p>PRIVATE KNOWLEDGE BODY</p>');

        $second = $this->knowledgeFixture();
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($second);
        $this->getJson('/api/v1/knowledge/articles/'.$id)->assertNotFound();
    }

    public function test_internal_query_filters_current_snapshot_and_escapes_search_wildcards(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);

        $percent = $this->createArticle($client, ['title' => 'Tarifa 100% segura', 'visibility' => 'public']);
        $underscore = $this->createArticle($client, ['title' => 'Código_A', 'visibility' => 'INTERNAL']);
        $this->createArticle($client, ['title' => 'Código XA', 'visibility' => 'CUSTOMER']);

        $this->getJson('/api/v1/knowledge/articles?q=%')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $percent['id']);
        $this->getJson('/api/v1/knowledge/articles?q=_')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $underscore['id']);
        $this->getJson('/api/v1/knowledge/articles?status=DRAFT&visibility=INTERNAL&category_id='.$client['category']->id.'&tag_id='.$client['tag']->id.'&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $underscore['id']);
        $this->getJson('/api/v1/knowledge/articles?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_version_lookup_returns_the_requested_immutable_snapshot(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $id = $this->createArticle($client, ['body_html' => '<p>One</p>'])['id'];
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'body_html' => '<p>Two</p>',
        ])->assertOk();

        app(TenantContext::class)->set((int) $client['tenant']->id);
        $article = KnowledgeArticle::findOrFail($id);
        $version = app(KnowledgeArticleService::class)->version($article, 1);

        $this->assertSame(1, $version->version);
        $this->assertSame('<p>One</p>', $version->body_html);
        $this->assertTrue($version->relationLoaded('category'));
        $this->assertTrue($version->relationLoaded('tags'));
        $this->assertSame(2, KnowledgeArticleVersion::query()->where('article_id', $id)->count());
    }

    private function authenticateKnowledge(array $client): void
    {
        $this->withToken($client['token'])
            ->withHeader('X-Tenant-ID', $client['tenant']->id);
    }

    private function articlePayload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Artículo de prueba',
            'body_html' => '<p>Contenido</p>',
            'visibility' => 'PUBLIC',
        ], $overrides);
    }

    private function createArticle(array $client, array $overrides = []): array
    {
        return $this->postJson('/api/v1/knowledge/articles', $this->articlePayload(array_replace([
            'category_id' => $client['category']->id,
            'tag_ids' => [$client['tag']->id],
        ], $overrides)))->assertCreated()->json('data');
    }
}
