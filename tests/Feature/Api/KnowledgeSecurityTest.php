<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\KnowledgeArticleVersion;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Models\Permission;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\KnowledgeTestCase;

class KnowledgeSecurityTest extends KnowledgeTestCase
{
    use RefreshDatabase;

    public function test_foreign_articles_are_hidden_on_every_nested_route(): void
    {
        $owner = $this->knowledgeFixture();
        $this->authenticateKnowledge($owner);
        $article = $this->createKnowledgeArticle($owner, [
            'body_html' => '<p>SECRET_TENANT_A</p>',
        ]);
        $this->patchJson('/api/v1/knowledge/articles/'.$article['id'], [
            'expected_version' => 1,
            'title' => 'Owner revision',
        ])->assertOk();

        $foreign = $this->knowledgeFixture();
        $this->reauthenticateKnowledge($foreign);
        $root = '/api/v1/knowledge/articles/'.$article['id'];

        $responses = [
            $this->getJson($root),
            $this->patchJson($root, ['expected_version' => 2, 'title' => 'Attack']),
            $this->getJson($root.'/versions'),
            $this->getJson($root.'/versions/1'),
            $this->postJson($root.'/publish', ['expected_version' => 2]),
            $this->postJson($root.'/archive'),
            $this->postJson($root.'/restore'),
            $this->postJson($root.'/versions/1/restore', ['expected_version' => 2]),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertStringNotContainsString('SECRET_TENANT_A', $response->getContent());
        }

        $list = $this->getJson('/api/v1/knowledge/articles')->assertOk();
        $this->assertStringNotContainsString('SECRET_TENANT_A', $list->getContent());
        $list->assertJsonCount(0, 'data');
    }

    public function test_view_permission_can_read_history_but_cannot_manage_or_publish(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client);
        $this->patchJson('/api/v1/knowledge/articles/'.$article['id'], [
            'expected_version' => 1,
            'title' => 'Second version',
        ])->assertOk();
        $this->setKnowledgePermissions($client, ['knowledge.view']);

        $root = '/api/v1/knowledge/articles/'.$article['id'];
        $this->getJson('/api/v1/knowledge/articles')->assertOk();
        $this->getJson($root)->assertOk();
        $this->getJson($root.'/versions')->assertOk();
        $this->getJson($root.'/versions/1')->assertOk();

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload($client))
            ->assertForbidden();
        $this->patchJson($root, ['expected_version' => 2, 'title' => 'Denied'])
            ->assertForbidden();
        $this->postJson($root.'/publish', ['expected_version' => 2])->assertForbidden();
        $this->postJson($root.'/archive')->assertForbidden();
        $this->postJson($root.'/restore')->assertForbidden();
        $this->postJson($root.'/versions/1/restore', ['expected_version' => 2])
            ->assertForbidden();
    }

    public function test_manage_with_view_can_edit_and_restore_history_but_cannot_publish(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client);
        $this->setKnowledgePermissions($client, ['knowledge.view', 'knowledge.manage']);
        $root = '/api/v1/knowledge/articles/'.$article['id'];

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload($client, [
            'title' => 'Manager created',
        ]))->assertCreated();
        $this->patchJson($root, ['expected_version' => 1, 'title' => 'Manager edit'])
            ->assertOk()->assertJsonPath('data.current_version.version', 2);
        $this->postJson($root.'/versions/1/restore', ['expected_version' => 2])
            ->assertCreated()->assertJsonPath('data.current_version.version', 3);

        $this->postJson($root.'/publish', ['expected_version' => 3])->assertForbidden();
        $this->postJson($root.'/archive')->assertForbidden();
        $this->postJson($root.'/restore')->assertForbidden();
    }

    public function test_publish_with_view_can_run_lifecycle_but_cannot_edit_or_restore_history(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client);
        $this->patchJson('/api/v1/knowledge/articles/'.$article['id'], [
            'expected_version' => 1,
            'title' => 'Ready to publish',
        ])->assertOk();
        $this->setKnowledgePermissions($client, ['knowledge.view', 'knowledge.publish']);
        $root = '/api/v1/knowledge/articles/'.$article['id'];

        $this->postJson('/api/v1/knowledge/articles', $this->articlePayload($client))
            ->assertForbidden();
        $this->patchJson($root, ['expected_version' => 2, 'title' => 'Denied edit'])
            ->assertForbidden();
        $this->postJson($root.'/versions/1/restore', ['expected_version' => 2])
            ->assertForbidden();

        $this->postJson($root.'/publish', ['expected_version' => 2])->assertOk();
        $this->postJson($root.'/archive')->assertOk();
        $this->postJson($root.'/restore')->assertOk();
    }

    public function test_publish_permission_without_view_cannot_read_or_run_lifecycle(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client);
        $this->setKnowledgePermissions($client, ['knowledge.publish']);
        $root = '/api/v1/knowledge/articles/'.$article['id'];

        $this->getJson('/api/v1/knowledge/articles')->assertForbidden();
        $this->getJson($root)->assertForbidden();
        $this->postJson($root.'/publish', ['expected_version' => 1])->assertForbidden();
        $this->postJson($root.'/archive')->assertForbidden();
        $this->postJson($root.'/restore')->assertForbidden();
    }

    public function test_new_revisions_reject_inactive_and_foreign_catalog_references_without_disclosure(): void
    {
        $owner = $this->knowledgeFixture();
        $this->authenticateKnowledge($owner);
        $article = $this->createKnowledgeArticle($owner);
        app(TenantContext::class)->set((int) $owner['tenant']->id);
        $inactiveCategory = KnowledgeCategory::create([
            'name' => 'Inactive category',
            'normalized_name' => 'inactive category',
            'position' => 20,
            'is_active' => false,
            'created_by' => $owner['user']->id,
        ]);
        $inactiveTag = KnowledgeTag::create([
            'name' => 'Inactive tag',
            'normalized_name' => 'inactive tag',
            'is_active' => false,
            'created_by' => $owner['user']->id,
        ]);

        $foreign = $this->knowledgeFixture();
        $foreign['category']->update(['name' => 'SECRET FOREIGN CATEGORY']);
        $foreign['tag']->update(['name' => 'SECRET FOREIGN TAG']);
        $this->reauthenticateKnowledge($owner);
        $root = '/api/v1/knowledge/articles/'.$article['id'];

        foreach ([$inactiveCategory->id, $foreign['category']->id] as $categoryId) {
            $response = $this->patchJson($root, [
                'expected_version' => 1,
                'category_id' => $categoryId,
            ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
            $this->assertStringNotContainsString('SECRET FOREIGN CATEGORY', $response->getContent());
        }

        foreach ([$inactiveTag->id, $foreign['tag']->id] as $tagId) {
            $response = $this->patchJson($root, [
                'expected_version' => 1,
                'tag_ids' => [$tagId],
            ])->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
            $this->assertStringNotContainsString('SECRET FOREIGN TAG', $response->getContent());
        }

        $this->assertSame(1, KnowledgeArticleVersion::query()
            ->where('article_id', $article['id'])->count());
    }

    public function test_article_creation_rejects_inactive_and_foreign_catalog_references_without_disclosure(): void
    {
        $owner = $this->knowledgeFixture();
        $this->authenticateKnowledge($owner);
        app(TenantContext::class)->set((int) $owner['tenant']->id);
        $inactiveCategory = KnowledgeCategory::create([
            'name' => 'SECRET INACTIVE CATEGORY',
            'normalized_name' => 'secret inactive category',
            'position' => 20,
            'is_active' => false,
            'created_by' => $owner['user']->id,
        ]);
        $inactiveTag = KnowledgeTag::create([
            'name' => 'SECRET INACTIVE TAG',
            'normalized_name' => 'secret inactive tag',
            'is_active' => false,
            'created_by' => $owner['user']->id,
        ]);

        $foreign = $this->knowledgeFixture();
        $foreign['category']->update(['name' => 'SECRET FOREIGN CATEGORY']);
        $foreign['tag']->update(['name' => 'SECRET FOREIGN TAG']);
        $this->reauthenticateKnowledge($owner);
        $cases = [
            ['category_id', $inactiveCategory->id, 'category_id'],
            ['tag_ids', [$inactiveTag->id], 'tag_ids'],
            ['category_id', $foreign['category']->id, 'category_id'],
            ['tag_ids', [$foreign['tag']->id], 'tag_ids'],
        ];
        $privateNames = [
            'SECRET INACTIVE CATEGORY',
            'SECRET INACTIVE TAG',
            'SECRET FOREIGN CATEGORY',
            'SECRET FOREIGN TAG',
        ];

        foreach ($cases as [$field, $value, $error]) {
            $response = $this->postJson(
                '/api/v1/knowledge/articles',
                $this->articlePayload($owner, [$field => $value]),
            )->assertUnprocessable()->assertJsonValidationErrors($error);
            foreach ($privateNames as $privateName) {
                $this->assertStringNotContainsString($privateName, $response->getContent());
            }
        }

        $this->assertDatabaseCount('knowledge_articles', 0);
        $this->assertDatabaseCount('knowledge_article_versions', 0);
    }

    public function test_internal_ids_and_public_uuids_are_strict_route_constraints(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client);

        foreach ([
            '/api/v1/knowledge/articles/not-a-number',
            '/api/v1/knowledge/articles/not-a-number/versions',
            '/api/v1/knowledge/articles/not-a-number/versions/1',
        ] as $uri) {
            $this->getJson($uri)->assertNotFound();
        }
        $this->patchJson('/api/v1/knowledge/articles/not-a-number', [
            'expected_version' => 1,
            'title' => 'Invalid route',
        ])->assertNotFound();
        foreach (['publish', 'archive', 'restore'] as $action) {
            $this->postJson('/api/v1/knowledge/articles/not-a-number/'.$action, [
                'expected_version' => 1,
            ])->assertNotFound();
        }
        $this->getJson('/api/v1/knowledge/articles/'.$article['id'].'/versions/not-a-number')
            ->assertNotFound();
        $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/versions/not-a-number/restore', [
            'expected_version' => 1,
        ])->assertNotFound();
        foreach (['categories', 'tags'] as $catalog) {
            $this->getJson('/api/v1/knowledge/'.$catalog.'/not-a-number')->assertNotFound();
            $this->patchJson('/api/v1/knowledge/'.$catalog.'/not-a-number', ['name' => 'Invalid'])
                ->assertNotFound();
            $this->deleteJson('/api/v1/knowledge/'.$catalog.'/not-a-number')->assertNotFound();
        }

        $invalidBase = '/api/v1/public/knowledge/not-a-uuid';
        foreach (['', '/categories', '/articles', '/articles/'.Str::uuid()] as $suffix) {
            $this->publicGet($invalidBase.$suffix)->assertNotFound();
        }
        $this->publicGet('/api/v1/public/knowledge/'.$client['base']->public_id.'/articles/not-a-uuid')
            ->assertNotFound();
    }

    public function test_internal_search_treats_wildcards_and_sql_like_text_literally(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $percent = $this->createKnowledgeArticle($client, ['title' => 'Rate 100% literal']);
        $underscore = $this->createKnowledgeArticle($client, ['title' => 'Code_A literal']);
        $injection = $this->createKnowledgeArticle($client, ['title' => "Guide ' OR 1=1 -- exact"]);
        $summary = $this->createKnowledgeArticle($client, [
            'title' => 'Ordinary title',
            'summary' => 'summary needle',
        ]);
        $this->createKnowledgeArticle($client, ['title' => 'Code XA unrelated']);

        $this->assertArticleIds(['q' => '%'], [$percent['id']]);
        $this->assertArticleIds(['q' => '_'], [$underscore['id']]);
        $this->assertArticleIds(['q' => "' OR 1=1 --"], [$injection['id']]);
        $this->assertArticleIds(['q' => 'summary needle'], [$summary['id']]);
    }

    public function test_internal_filters_dates_and_every_sort_are_applied_to_current_snapshots(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 09:00:00');

        try {
            $client = $this->knowledgeFixture();
            $this->authenticateKnowledge($client);
            $otherCategory = KnowledgeCategory::create([
                'name' => 'Technical',
                'normalized_name' => 'technical',
                'position' => 20,
                'created_by' => $client['user']->id,
            ]);
            $otherTag = KnowledgeTag::create([
                'name' => 'Advanced',
                'normalized_name' => 'advanced',
                'created_by' => $client['user']->id,
            ]);

            $alpha = $this->createKnowledgeArticle($client, [
                'title' => 'Alpha',
                'visibility' => 'PUBLIC',
            ]);
            CarbonImmutable::setTestNow('2026-01-02 09:00:00');
            $beta = $this->createKnowledgeArticle($client, [
                'title' => 'Beta',
                'visibility' => 'INTERNAL',
                'category_id' => $otherCategory->id,
                'tag_ids' => [$otherTag->id],
            ]);
            $this->postJson('/api/v1/knowledge/articles/'.$beta['id'].'/publish', [
                'expected_version' => 1,
            ])->assertOk();

            CarbonImmutable::setTestNow('2026-01-03 09:00:00');
            $gamma = $this->createKnowledgeArticle($client, [
                'title' => 'Gamma',
                'visibility' => 'CUSTOMER',
            ]);
            $this->postJson('/api/v1/knowledge/articles/'.$gamma['id'].'/publish', [
                'expected_version' => 1,
            ])->assertOk();
            CarbonImmutable::setTestNow('2026-01-03 10:00:00');
            $this->patchJson('/api/v1/knowledge/articles/'.$gamma['id'], [
                'expected_version' => 1,
                'summary' => 'Unpublished revision',
            ])->assertOk();

            CarbonImmutable::setTestNow('2026-01-04 09:00:00');
            $delta = $this->createKnowledgeArticle($client, [
                'title' => 'Delta',
                'visibility' => 'PUBLIC',
                'category_id' => $otherCategory->id,
                'tag_ids' => [$otherTag->id],
            ]);
            $this->postJson('/api/v1/knowledge/articles/'.$delta['id'].'/archive')->assertOk();

            $this->assertArticleIds(['status' => 'DRAFT'], [$alpha['id']]);
            $this->assertArticleIds(['status' => 'PUBLISHED'], [$gamma['id'], $beta['id']]);
            $this->assertArticleIds(['status' => 'ARCHIVED'], [$delta['id']]);
            $this->assertArticleIds(['visibility' => 'INTERNAL'], [$beta['id']]);
            $this->assertArticleIds(['category_id' => $otherCategory->id], [$delta['id'], $beta['id']]);
            $this->assertArticleIds(['tag_id' => $otherTag->id], [$delta['id'], $beta['id']]);
            $this->assertArticleIds(['has_unpublished_changes' => 1], [
                $delta['id'], $gamma['id'], $alpha['id'],
            ]);
            $this->assertArticleIds(['has_unpublished_changes' => 0], [$beta['id']]);
            $this->assertArticleIds([
                'created_from' => '2026-01-02',
                'created_to' => '2026-01-03',
            ], [$gamma['id'], $beta['id']]);
            $this->assertArticleIds(['sort' => 'created_at', 'direction' => 'asc'], [
                $alpha['id'], $beta['id'], $gamma['id'], $delta['id'],
            ]);
            $this->assertArticleIds(['sort' => 'updated_at', 'direction' => 'desc'], [
                $delta['id'], $gamma['id'], $beta['id'], $alpha['id'],
            ]);
            $this->assertArticleIds([
                'status' => 'PUBLISHED',
                'sort' => 'published_at',
                'direction' => 'asc',
            ], [$beta['id'], $gamma['id']]);
            $this->assertArticleIds(['sort' => 'title', 'direction' => 'asc'], [
                $alpha['id'], $beta['id'], $delta['id'], $gamma['id'],
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_internal_sort_uses_article_id_as_a_deterministic_tie_breaker(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $first = $this->createKnowledgeArticle($client, ['title' => 'Same title']);
        $second = $this->createKnowledgeArticle($client, ['title' => 'Same title']);

        $this->assertArticleIds(['sort' => 'title', 'direction' => 'asc'], [
            $first['id'], $second['id'],
        ]);
        $this->assertArticleIds(['sort' => 'title', 'direction' => 'desc'], [
            $second['id'], $first['id'],
        ]);
    }

    public function test_internal_filter_input_is_strictly_validated(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);

        foreach ([
            ['sort' => 'title;drop table knowledge_articles'],
            ['direction' => 'sideways'],
            ['has_unpublished_changes' => 'maybe'],
            ['created_from' => 'not-a-date'],
            ['created_to' => '2026-02-30'],
            ['per_page' => 0],
            ['per_page' => 101],
        ] as $query) {
            $field = array_key_first($query);
            $this->getJson('/api/v1/knowledge/articles?'.http_build_query($query))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_article_mutations_reject_server_controlled_fields_and_slug(): void
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
            $this->postJson('/api/v1/knowledge/articles', $this->articlePayload($client, [
                $field => $value,
            ]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $article = $this->createKnowledgeArticle($client);
        foreach ($controlled as $field => $value) {
            $this->patchJson('/api/v1/knowledge/articles/'.$article['id'], [
                'expected_version' => 1,
                'title' => 'Rejected revision',
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['status' => 'PUBLISHED', 'slug' => 'readable-id'] as $field => $value) {
            $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/publish', [
                'expected_version' => 1,
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/versions/1/restore', [
                'expected_version' => 1,
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertSame(1, KnowledgeArticleVersion::query()
            ->where('article_id', $article['id'])->count());
    }

    public function test_version_list_omits_private_body_while_single_version_detail_includes_it(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client, [
            'body_html' => '<p>PRIVATE_VERSION_BODY_V1</p>',
        ]);
        $root = '/api/v1/knowledge/articles/'.$article['id'];
        $this->patchJson($root, [
            'expected_version' => 1,
            'body_html' => '<p>PRIVATE_VERSION_BODY_V2</p>',
        ])->assertOk();

        $versions = $this->getJson($root.'/versions')->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('PRIVATE_VERSION_BODY', $versions->getContent());
        $versions->assertJsonMissingPath('data.0.body_html')
            ->assertJsonMissingPath('data.1.body_html');

        $this->getJson($root.'/versions/1')->assertOk()
            ->assertJsonPath('data.body_html', '<p>PRIVATE_VERSION_BODY_V1</p>');
    }

    public function test_public_categories_recursively_omit_internal_projection_keys(): void
    {
        $client = $this->knowledgeFixture(public: true);
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client);
        $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/publish', [
            'expected_version' => 1,
        ])->assertOk();

        $categories = $this->publicGet(
            '/api/v1/public/knowledge/'.$client['base']->public_id.'/categories',
        )->assertOk()->assertJsonCount(1, 'data');
        $this->assertPublicKeysAreSafe($categories->json('data'));
    }

    public function test_audits_lists_history_and_public_projection_preserve_privacy_and_snapshots(): void
    {
        $client = $this->knowledgeFixture(public: true);
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client, [
            'title' => 'Private workflow',
            'body_html' => '<p>PRIVATE_KNOWLEDGE_BODY_V1</p>',
        ]);
        $root = '/api/v1/knowledge/articles/'.$article['id'];

        $this->patchJson($root, [
            'expected_version' => 1,
            'body_html' => '<p>PRIVATE_KNOWLEDGE_BODY_V2</p>',
        ])->assertOk();
        $this->postJson($root.'/versions/1/restore', ['expected_version' => 2])
            ->assertCreated()->assertJsonPath('data.current_version.version', 3);
        $this->postJson($root.'/publish', ['expected_version' => 3])->assertOk();
        $this->postJson($root.'/archive')->assertOk();
        $this->postJson($root.'/restore')->assertOk();
        $this->postJson($root.'/publish', ['expected_version' => 3])->assertOk();

        $this->patchJson('/api/v1/knowledge/categories/'.$client['category']->id, [
            'is_active' => false,
        ])->assertOk();
        $this->patchJson('/api/v1/knowledge/tags/'.$client['tag']->id, [
            'is_active' => false,
        ])->assertOk();

        $list = $this->getJson('/api/v1/knowledge/articles')->assertOk();
        $this->assertStringNotContainsString('PRIVATE_KNOWLEDGE_BODY', $list->getContent());
        $list->assertJsonMissingPath('data.0.current_version.body_html')
            ->assertJsonMissingPath('data.0.published_version.body_html');

        $this->getJson($root.'/versions/1')->assertOk()
            ->assertJsonPath('data.category.name', $client['category']->name)
            ->assertJsonPath('data.category.is_active', false)
            ->assertJsonPath('data.tags.0.name', $client['tag']->name)
            ->assertJsonPath('data.tags.0.is_active', false);

        $this->deleteJson($root)->assertStatus(405);
        $this->deleteJson($root.'/versions/1')->assertStatus(405);
        $this->deleteJson('/api/v1/knowledge/categories/'.$client['category']->id)
            ->assertConflict();
        $this->deleteJson('/api/v1/knowledge/tags/'.$client['tag']->id)
            ->assertConflict();

        $audits = AuditLog::query()
            ->where('entity_type', 'knowledge_articles')
            ->where('entity_id', (string) $article['id'])
            ->get();
        $auditJson = $audits->toJson();
        $this->assertStringNotContainsString('PRIVATE_KNOWLEDGE_BODY', $auditJson);
        $this->assertStringNotContainsString('body_html', $auditJson);
        foreach ([
            'knowledge.article.created',
            'knowledge.article.revised',
            'knowledge.article.version_restored',
            'knowledge.article.published',
            'knowledge.article.archived',
            'knowledge.article.restored',
        ] as $action) {
            $audit = $audits->firstWhere('action', $action);
            $this->assertNotNull($audit, 'Missing audit action '.$action);
            $this->assertSame($client['user']->id, $audit->user_id);
            $this->assertArrayHasKey('version', $audit->new_values);
        }
        $this->assertSame('PUBLISHED', $audits
            ->firstWhere('action', 'knowledge.article.published')->new_values['state']);
        $this->assertSame('ARCHIVED', $audits
            ->firstWhere('action', 'knowledge.article.archived')->new_values['state']);
        $this->assertSame('DRAFT', $audits
            ->firstWhere('action', 'knowledge.article.restored')->new_values['state']);

        $publicRoot = '/api/v1/public/knowledge/'.$client['base']->public_id;
        $publicList = $this->publicGet($publicRoot.'/articles')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.name', $client['category']->name)
            ->assertJsonPath('data.0.tags.0.name', $client['tag']->name)
            ->assertJsonMissingPath('data.0.body_html');
        $publicDetail = $this->publicGet(
            $publicRoot.'/articles/'.$article['public_id']
        )->assertOk()->assertJsonPath('data.body_html', '<p>PRIVATE_KNOWLEDGE_BODY_V1</p>');
        $this->publicGet($publicRoot.'/categories')->assertOk()
            ->assertJsonPath('data.0.name', $client['category']->name);

        $this->assertPublicKeysAreSafe($publicList->json('data'));
        $this->assertPublicKeysAreSafe($publicDetail->json('data'));
    }

    private function authenticateKnowledge(array $client): void
    {
        $this->withToken($client['token'])
            ->withHeader('X-Tenant-ID', $client['tenant']->id);
    }

    private function reauthenticateKnowledge(array $client): void
    {
        app(TenantContext::class)->set((int) $client['tenant']->id);
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($client);
    }

    private function setKnowledgePermissions(array $client, array $keys): void
    {
        $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id');
        $client['user']->memberships()->firstOrFail()->role->permissions()->sync($permissionIds);
        $this->reauthenticateKnowledge($client);
    }

    private function articlePayload(array $client, array $overrides = []): array
    {
        return array_replace([
            'title' => 'Security article',
            'summary' => 'Security summary',
            'body_html' => '<p>Security body</p>',
            'visibility' => 'PUBLIC',
            'category_id' => $client['category']->id,
            'tag_ids' => [$client['tag']->id],
        ], $overrides);
    }

    private function createKnowledgeArticle(array $client, array $overrides = []): array
    {
        return $this->postJson(
            '/api/v1/knowledge/articles',
            $this->articlePayload($client, $overrides),
        )->assertCreated()->json('data');
    }

    private function assertArticleIds(array $query, array $expected): TestResponse
    {
        $response = $this->getJson(
            '/api/v1/knowledge/articles?'.http_build_query($query),
        )->assertOk();
        $this->assertSame($expected, array_column($response->json('data'), 'id'));

        return $response;
    }

    private function publicGet(string $uri): TestResponse
    {
        return $this->withoutHeader('Authorization')
            ->withoutHeader('X-Tenant-ID')
            ->getJson($uri);
    }

    private function assertPublicKeysAreSafe(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $forbidden = [
            'id',
            'tenant_id',
            'article_id',
            'category_id',
            'author_id',
            'created_by',
            'updated_by',
            'current_version_id',
            'published_version_id',
            'current_version',
            'published_version',
        ];
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbidden);
            }
            $this->assertPublicKeysAreSafe($nested);
        }
    }
}
