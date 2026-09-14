<?php

namespace Tests\Feature\Api;

use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\KnowledgeTestCase;

class PublicKnowledgeApiTest extends KnowledgeTestCase
{
    use RefreshDatabase;

    public function test_public_api_exposes_only_the_published_public_snapshot(): void
    {
        $client = $this->knowledgeFixture(public: true);
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client, '<p>Versión pública uno</p>', 'PUBLIC');
        $baseId = $client['base']->public_id;

        $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles/'.$article['public_id'])
            ->assertNotFound();

        $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/publish', [
            'expected_version' => 1,
        ])->assertOk();

        $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles/'.$article['public_id'])
            ->assertOk()
            ->assertJsonPath('data.body_html', '<p>Versión pública uno</p>')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.author_id')
            ->assertJsonMissingPath('data.current_version')
            ->assertJsonMissingPath('data.has_unpublished_changes');

        $this->patchJson('/api/v1/knowledge/articles/'.$article['id'], [
            'expected_version' => 1,
            'body_html' => '<p>Borrador privado dos</p>',
        ])->assertOk();

        $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles/'.$article['public_id'])
            ->assertOk()
            ->assertJsonPath('data.body_html', '<p>Versión pública uno</p>');

        $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles')
            ->assertOk()
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonMissingPath('data.0.body_html')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.tenant_id')
            ->assertJsonMissingPath('data.0.current_version');
    }

    public function test_public_api_hides_non_public_and_ineligible_articles_with_not_found(): void
    {
        $client = $this->knowledgeFixture(public: true);
        $this->authenticateKnowledge($client);
        $customer = $this->createKnowledgeArticle($client, '<p>CUSTOMER_SECRET</p>', 'CUSTOMER');
        $internal = $this->createKnowledgeArticle($client, '<p>INTERNAL_SECRET</p>', 'INTERNAL');
        $archived = $this->createKnowledgeArticle($client, '<p>ARCHIVED_SECRET</p>', 'PUBLIC');
        $public = $this->createKnowledgeArticle($client, '<p>PUBLIC_OK</p>', 'PUBLIC');

        foreach ([$customer, $internal, $archived, $public] as $article) {
            $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/publish', ['expected_version' => 1])
                ->assertOk();
        }
        $this->postJson('/api/v1/knowledge/articles/'.$archived['id'].'/archive')->assertOk();

        $baseId = $client['base']->public_id;
        foreach ([$customer, $internal, $archived] as $article) {
            $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles/'.$article['public_id'])
                ->assertNotFound();
        }
        $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles/'.Str::uuid())->assertNotFound();
        $this->publicGet('/api/v1/public/knowledge/'.$baseId.'/articles/not-a-uuid')->assertNotFound();

        $foreign = $this->knowledgeFixture(public: true);
        $this->publicGet('/api/v1/public/knowledge/'.$foreign['base']->public_id.'/articles/'.$public['public_id'])
            ->assertNotFound();

        $client['base']->update(['is_public' => false]);
        $this->publicGet('/api/v1/public/knowledge/'.$baseId)->assertNotFound();
    }

    public function test_public_catalog_uses_published_snapshot_for_filters_sorting_and_categories(): void
    {
        $client = $this->knowledgeFixture(public: true);
        $this->authenticateKnowledge($client);
        $legacyCategory = $client['category'];
        $legacyTag = $client['tag'];
        $draftCategory = KnowledgeCategory::create([
            'name' => 'Borradores',
            'normalized_name' => 'borradores',
            'position' => 20,
            'created_by' => $client['user']->id,
        ]);
        $draftTag = KnowledgeTag::create([
            'name' => 'Solo borrador',
            'normalized_name' => 'solo borrador',
            'created_by' => $client['user']->id,
        ]);
        $literal = $this->createKnowledgeArticle($client, '<p>Literal percent</p>', 'PUBLIC', [
            'title' => '100% público',
            'summary' => 'literal _ snapshot',
        ]);
        $alphabetical = $this->createKnowledgeArticle($client, '<p>Alphabetical</p>', 'PUBLIC', [
            'title' => 'Alfa público',
        ]);
        $hidden = $this->createKnowledgeArticle($client, '<p>CUSTOMER_ONLY</p>', 'CUSTOMER', [
            'title' => 'Customer público',
        ]);

        foreach ([$literal, $alphabetical, $hidden] as $article) {
            $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/publish', ['expected_version' => 1])
                ->assertOk();
        }
        $this->patchJson('/api/v1/knowledge/articles/'.$literal['id'], [
            'expected_version' => 1,
            'title' => 'Borrador no indexable',
            'summary' => 'draft only',
            'category_id' => $draftCategory->id,
            'tag_ids' => [$draftTag->id],
        ])->assertOk();
        $legacyCategory->update(['is_active' => false]);

        $base = '/api/v1/public/knowledge/'.$client['base']->public_id;
        $this->publicGet($base.'/articles?q=%25&category_id='.$legacyCategory->id.'&tag_id='.$legacyTag->id.'&sort=title&direction=asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $literal['public_id'])
            ->assertJsonPath('data.0.title', '100% público')
            ->assertJsonPath('data.0.summary', 'literal _ snapshot');
        $this->publicGet($base.'/articles?q=_%20snapshot')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $literal['public_id']);
        $this->publicGet($base.'/articles?category_id='.$draftCategory->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->publicGet($base.'/articles?tag_id='.$draftTag->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->publicGet($base.'/articles?sort=title&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', '100% público')
            ->assertJsonPath('data.1.title', 'Alfa público');
        $this->publicGet($base.'/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Facturación')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.tenant_id');
        $this->publicGet($base.'/articles?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->publicGet($base.'/articles?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->publicGet($base.'/articles?category_id=0')->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->publicGet($base.'/articles?sort=updated_at')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->publicGet($base.'/articles?direction=sideways')->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->publicGet($base.'/articles?q='.str_repeat('x', 121))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_public_reads_restore_tenant_context_after_success_and_exception(): void
    {
        $owner = $this->knowledgeFixture(public: true);
        $this->authenticateKnowledge($owner);
        $article = $this->createKnowledgeArticle($owner, '<p>Context-safe</p>', 'PUBLIC');
        $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/publish', ['expected_version' => 1])
            ->assertOk();
        $foreign = $this->knowledgeFixture();
        $context = app(TenantContext::class);
        $context->set((int) $foreign['tenant']->id);
        $base = '/api/v1/public/knowledge/'.$owner['base']->public_id;

        $this->publicGet($base.'/articles/'.$article['public_id'])->assertOk();
        $this->assertSame((int) $foreign['tenant']->id, $context->id());
        $this->publicGet($base.'/articles/'.Str::uuid())->assertNotFound();
        $this->assertSame((int) $foreign['tenant']->id, $context->id());
    }

    public function test_public_knowledge_throttle_allows_sixty_requests_per_base_and_ip(): void
    {
        Cache::store()->flush();
        $client = $this->knowledgeFixture(public: true);
        $url = '/api/v1/public/knowledge/'.$client['base']->public_id;

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->publicGet($url)->assertOk();
        }

        $this->publicGet($url)->assertStatus(429);
    }

    private function authenticateKnowledge(array $client): void
    {
        $this->withToken($client['token'])
            ->withHeader('X-Tenant-ID', $client['tenant']->id);
    }

    private function createKnowledgeArticle(array $client, string $body, string $visibility, array $overrides = []): array
    {
        return $this->postJson('/api/v1/knowledge/articles', array_replace([
            'title' => 'Artículo público',
            'summary' => 'Resumen publicado',
            'body_html' => $body,
            'visibility' => $visibility,
            'category_id' => $client['category']->id,
            'tag_ids' => [$client['tag']->id],
        ], $overrides))->assertCreated()->json('data');
    }

    private function publicGet(string $uri)
    {
        return $this->withoutHeader('Authorization')
            ->withoutHeader('X-Tenant-ID')
            ->getJson($uri);
    }
}
