<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\KnowledgeArticleVersion;
use App\Models\Permission;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\KnowledgeTestCase;

class KnowledgePublicationTest extends KnowledgeTestCase
{
    use RefreshDatabase;

    public function test_published_version_stays_live_until_a_new_revision_is_published(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        try {
            $client = $this->knowledgeFixture();
            $this->authenticateKnowledge($client);
            $article = $this->createKnowledgeArticle($client, '<p>Publicada uno</p>');
            $id = $article['id'];

            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
                'expected_version' => 1,
            ])->assertOk()
                ->assertJsonPath('data.status', 'PUBLISHED')
                ->assertJsonPath('data.published_version.version', 1);

            $this->assertDatabaseHas('knowledge_articles', [
                'id' => $id,
                'published_version_id' => $article['current_version']['id'],
                'published_at' => '2026-09-13 10:00:00',
                'archived_at' => null,
            ]);

            CarbonImmutable::setTestNow('2026-09-13 10:30:00');
            $this->patchJson('/api/v1/knowledge/articles/'.$id, [
                'expected_version' => 1,
                'body_html' => '<p>Borrador dos</p>',
            ])->assertOk()
                ->assertJsonPath('data.current_version.version', 2)
                ->assertJsonPath('data.published_version.version', 1)
                ->assertJsonPath('data.has_unpublished_changes', true);

            CarbonImmutable::setTestNow('2026-09-13 11:00:00');
            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
                'expected_version' => 2,
            ])->assertOk()
                ->assertJsonPath('data.published_version.version', 2)
                ->assertJsonPath('data.has_unpublished_changes', false);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_publish_rejects_stale_or_archived_articles_and_is_idempotent_for_the_current_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        try {
            $client = $this->knowledgeFixture();
            $this->authenticateKnowledge($client);
            $article = $this->createKnowledgeArticle($client, '<p>Audited publication</p>');
            $id = $article['id'];

            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
                'expected_version' => 2,
            ])->assertConflict();

            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
                'expected_version' => 1,
            ])->assertOk();

            CarbonImmutable::setTestNow('2026-09-13 11:00:00');
            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
                'expected_version' => 1,
            ])->assertOk();

            $this->assertSame(1, AuditLog::query()
                ->where('action', 'knowledge.article.published')
                ->where('entity_id', (string) $id)
                ->count());
            $this->assertDatabaseHas('knowledge_articles', [
                'id' => $id,
                'updated_at' => '2026-09-13 10:00:00',
            ]);

            $this->postJson('/api/v1/knowledge/articles/'.$id.'/archive')->assertOk();
            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
                'expected_version' => 1,
            ])->assertConflict();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_lifecycle_actions_require_publish_permission_and_validate_expected_version_payloads(): void
    {
        $manager = $this->knowledgeFixture(['knowledge.view', 'knowledge.manage']);
        $this->authenticateKnowledge($manager);
        $id = $this->createKnowledgeArticle($manager, '<p>Permission check</p>')['id'];

        $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', [
            'expected_version' => 1,
        ])->assertForbidden();

        $publisher = $this->knowledgeFixture(['knowledge.view', 'knowledge.publish', 'knowledge.manage']);
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($publisher);
        $publisherId = $this->createKnowledgeArticle($publisher, '<p>Validation check</p>')['id'];

        $this->postJson('/api/v1/knowledge/articles/'.$publisherId.'/publish', [
            'expected_version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson('/api/v1/knowledge/articles/'.$publisherId.'/publish', [
            'expected_version' => 1,
            'status' => 'PUBLISHED',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_manager_with_view_can_restore_a_historical_version_without_publish_permission(): void
    {
        $manager = $this->knowledgeFixture(['knowledge.view', 'knowledge.manage']);
        $this->authenticateKnowledge($manager);
        $id = $this->createKnowledgeArticle($manager, '<p>Version one</p>')['id'];
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'body_html' => '<p>Version two</p>',
        ])->assertOk();

        $this->postJson('/api/v1/knowledge/articles/'.$id.'/versions/1/restore', [
            'expected_version' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.current_version.version', 3)
            ->assertJsonPath('data.current_version.body_html', '<p>Version one</p>');
    }

    public function test_publisher_with_view_cannot_restore_a_historical_version_without_manage_permission(): void
    {
        $publisher = $this->knowledgeFixture();
        $this->authenticateKnowledge($publisher);
        $id = $this->createKnowledgeArticle($publisher, '<p>Version one</p>')['id'];
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'body_html' => '<p>Version two</p>',
        ])->assertOk();

        $publisher['user']->memberships()->firstOrFail()->role->permissions()->detach(
            Permission::where('key', 'knowledge.manage')->value('id'),
        );
        app(TenantContext::class)->set((int) $publisher['tenant']->id);
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($publisher);

        $this->assertTrue($publisher['user']->hasPermission('knowledge.view'));
        $this->assertTrue($publisher['user']->hasPermission('knowledge.publish'));
        $this->assertFalse($publisher['user']->hasPermission('knowledge.manage'));
        $this->postJson('/api/v1/knowledge/articles/'.$id.'/versions/1/restore', [
            'expected_version' => 2,
        ])->assertForbidden();
    }

    public function test_viewer_cannot_restore_a_historical_version(): void
    {
        $viewer = $this->knowledgeFixture();
        $this->authenticateKnowledge($viewer);
        $id = $this->createKnowledgeArticle($viewer, '<p>Version one</p>')['id'];
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'body_html' => '<p>Version two</p>',
        ])->assertOk();

        $viewer['user']->memberships()->firstOrFail()->role->permissions()->detach(
            Permission::query()->whereIn('key', ['knowledge.manage', 'knowledge.publish'])->pluck('id'),
        );
        app(TenantContext::class)->set((int) $viewer['tenant']->id);
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($viewer);

        $this->assertTrue($viewer['user']->hasPermission('knowledge.view'));
        $this->assertFalse($viewer['user']->hasPermission('knowledge.manage'));
        $this->assertFalse($viewer['user']->hasPermission('knowledge.publish'));
        $this->postJson('/api/v1/knowledge/articles/'.$id.'/versions/1/restore', [
            'expected_version' => 2,
        ])->assertForbidden();
    }

    public function test_archive_is_idempotent_and_restore_returns_archived_article_to_draft_without_a_public_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        try {
            $client = $this->knowledgeFixture();
            $this->authenticateKnowledge($client);
            $article = $this->createKnowledgeArticle($client, '<p>Archivable</p>');
            $id = $article['id'];

            $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', ['expected_version' => 1])
                ->assertOk();
            CarbonImmutable::setTestNow('2026-09-13 11:00:00');
            $this->postJson('/api/v1/knowledge/articles/'.$id.'/archive')->assertOk()
                ->assertJsonPath('data.status', 'ARCHIVED')
                ->assertJsonPath('data.current_version.version', 1)
                ->assertJsonPath('data.published_version.version', 1);

            $this->assertDatabaseHas('knowledge_articles', [
                'id' => $id,
                'archived_at' => '2026-09-13 11:00:00',
                'published_version_id' => $article['current_version']['id'],
            ]);

            CarbonImmutable::setTestNow('2026-09-13 12:00:00');
            $this->postJson('/api/v1/knowledge/articles/'.$id.'/archive')->assertOk();
            $this->assertSame(1, AuditLog::query()
                ->where('action', 'knowledge.article.archived')
                ->where('entity_id', (string) $id)
                ->count());
            $this->assertDatabaseHas('knowledge_articles', [
                'id' => $id,
                'updated_at' => '2026-09-13 11:00:00',
            ]);

            $this->postJson('/api/v1/knowledge/articles/'.$id.'/restore')->assertOk()
                ->assertJsonPath('data.status', 'DRAFT')
                ->assertJsonPath('data.published_version', null);
            $this->assertDatabaseHas('knowledge_articles', [
                'id' => $id,
                'published_version_id' => null,
                'published_at' => null,
                'archived_at' => null,
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_restore_rejects_articles_that_are_not_archived(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $id = $this->createKnowledgeArticle($client, '<p>Still draft</p>')['id'];

        $this->postJson('/api/v1/knowledge/articles/'.$id.'/restore')->assertConflict();
    }

    public function test_history_is_descending_and_restoring_a_snapshot_creates_a_new_immutable_version(): void
    {
        $client = $this->knowledgeFixture();
        $this->authenticateKnowledge($client);
        $article = $this->createKnowledgeArticle($client, '<p>Version one</p>');
        $id = $article['id'];

        $this->postJson('/api/v1/knowledge/articles/'.$id.'/publish', ['expected_version' => 1])
            ->assertOk();
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 1,
            'body_html' => '<p>Version two</p>',
        ])->assertOk();
        $this->patchJson('/api/v1/knowledge/articles/'.$id, [
            'expected_version' => 2,
            'body_html' => '<p>Version three</p>',
        ])->assertOk();

        $this->getJson('/api/v1/knowledge/articles/'.$id.'/versions?per_page=2')->assertOk()
            ->assertJsonPath('data.0.version', 3)
            ->assertJsonPath('data.1.version', 2)
            ->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/knowledge/articles/'.$id.'/versions/1')->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.body_html', '<p>Version one</p>');
        $this->getJson('/api/v1/knowledge/articles/'.$id.'/versions?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');

        $other = $this->createKnowledgeArticle($client, '<p>Other article</p>');
        $this->getJson('/api/v1/knowledge/articles/'.$other['id'].'/versions/3')->assertNotFound();

        $this->postJson('/api/v1/knowledge/articles/'.$id.'/versions/1/restore', [
            'expected_version' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.current_version.version', 4)
            ->assertJsonPath('data.current_version.body_html', '<p>Version one</p>')
            ->assertJsonPath('data.published_version.version', 1);

        foreach ([
            1 => '<p>Version one</p>',
            2 => '<p>Version two</p>',
            3 => '<p>Version three</p>',
        ] as $version => $body) {
            $this->assertDatabaseHas('knowledge_article_versions', [
                'article_id' => $id,
                'version' => $version,
                'body_html' => $body,
            ]);
        }
        $this->assertDatabaseCount('knowledge_article_versions', 5);
        $this->assertDatabaseHas('knowledge_article_versions', [
            'article_id' => $id,
            'version' => 4,
            'body_html' => '<p>Version one</p>',
            'category_id' => $client['category']->id,
        ]);
        $this->assertDatabaseHas('knowledge_article_version_tags', [
            'version_id' => KnowledgeArticleVersion::query()
                ->where('article_id', $id)->where('version', 4)->value('id'),
            'tag_id' => $client['tag']->id,
        ]);

        $audit = AuditLog::query()->where('action', 'knowledge.article.version_restored')
            ->where('entity_id', (string) $id)->firstOrFail();
        $this->assertStringNotContainsString('Version one', $audit->toJson());

        $this->postJson('/api/v1/knowledge/articles/'.$id.'/archive')->assertOk();
        $this->postJson('/api/v1/knowledge/articles/'.$id.'/versions/1/restore', [
            'expected_version' => 4,
        ])->assertConflict();
    }

    public function test_nested_version_resources_are_hidden_from_foreign_tenants(): void
    {
        $owner = $this->knowledgeFixture();
        $this->authenticateKnowledge($owner);
        $article = $this->createKnowledgeArticle($owner, '<p>Private version</p>');

        $foreign = $this->knowledgeFixture();
        $this->app['auth']->forgetGuards();
        $this->authenticateKnowledge($foreign);

        $this->getJson('/api/v1/knowledge/articles/'.$article['id'].'/versions/1')->assertNotFound();
        $this->postJson('/api/v1/knowledge/articles/'.$article['id'].'/versions/1/restore', [
            'expected_version' => 1,
        ])->assertNotFound();
    }

    private function authenticateKnowledge(array $client): void
    {
        $this->withToken($client['token'])
            ->withHeader('X-Tenant-ID', $client['tenant']->id);
    }

    private function createKnowledgeArticle(array $client, string $body): array
    {
        return $this->postJson('/api/v1/knowledge/articles', [
            'title' => 'Lifecycle article',
            'body_html' => $body,
            'visibility' => 'PUBLIC',
            'category_id' => $client['category']->id,
            'tag_ids' => [$client['tag']->id],
        ])->assertCreated()->json('data');
    }
}
