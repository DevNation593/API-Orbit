<?php

use App\Models\FileRecord;
use App\Models\Permission;
use App\Models\Pipeline;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns a permission-aware customer overview and paginated timeline', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $company = $api->postJson('/api/v1/organizations', ['name' => 'Acme Ecuador'])->assertCreated()->json('data');
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@acme.test',
        'organization_ids' => [$company['id']],
    ])->assertCreated()->json('data');
    $pipeline = Pipeline::create(['tenant_id' => $client['tenant']->id, 'name' => 'Sales', 'active' => true]);
    $stage = $pipeline->stages()->create(['name' => 'Qualified', 'position' => 1, 'probability' => 30]);
    $api->postJson('/api/v1/deals', [
        'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'contact_id' => $contact['id'],
        'name' => 'Acme expansion', 'value' => '1250.00', 'currency' => 'USD',
    ])->assertCreated();
    $api->postJson('/api/v1/tasks', [
        'title' => 'Call Ada', 'related_type' => 'contact', 'related_id' => $contact['id'],
    ])->assertCreated();

    $api->getJson('/api/v1/contacts/'.$contact['id'].'/overview?recent_limit=3')
        ->assertOk()
        ->assertJsonPath('data.contact.id', $contact['id'])
        ->assertJsonPath('data.modules.companies.count', 1)
        ->assertJsonPath('data.modules.opportunities.count', 1)
        ->assertJsonPath('data.modules.tasks.count', 1)
        ->assertJsonPath('data.modules.quotes.count', 0)
        ->assertJsonCount(7, 'data.unavailable_modules');

    $timeline = $api->getJson('/api/v1/contacts/'.$contact['id'].'/timeline?per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');
    expect($timeline->json('meta.total'))->toBeGreaterThanOrEqual(4);
    expect(collect($timeline->json('data'))->every(
        fn (array $event): bool => isset($event['event'], $event['source'], $event['occurred_at']),
    ))->toBeTrue();
});

it('detects and merges duplicate contacts while preserving their related records', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $company = $api->postJson('/api/v1/organizations', ['name' => 'Merge Corp'])->assertCreated()->json('data');
    $target = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Ana', 'email' => 'ANA@example.com',
    ])->assertCreated()->json('data');
    $source = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Ana María', 'last_name' => 'Pérez', 'email' => 'ana@example.com',
        'organization_ids' => [$company['id']],
    ])->assertCreated()->json('data');
    $pipeline = Pipeline::create(['tenant_id' => $client['tenant']->id, 'name' => 'Merge pipeline', 'active' => true]);
    $stage = $pipeline->stages()->create(['name' => 'Open', 'position' => 1, 'probability' => 20]);
    $deal = $api->postJson('/api/v1/deals', [
        'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'contact_id' => $source['id'],
        'name' => 'Preserved deal', 'value' => '400.00', 'currency' => 'USD',
    ])->assertCreated()->json('data');
    $task = $api->postJson('/api/v1/tasks', [
        'title' => 'Preserved task', 'related_type' => 'contact', 'related_id' => $source['id'],
    ])->assertCreated()->json('data');
    $relation = $api->postJson('/api/v1/relations', [
        'relation_type' => 'works_at', 'from_type' => 'contact', 'from_id' => $source['id'],
        'to_type' => 'organization', 'to_id' => $company['id'],
    ])->assertCreated()->json('data');
    $tag = $api->postJson('/api/v1/tags', ['name' => 'VIP', 'color' => '#112233'])->assertCreated()->json('data');
    $assignment = $api->postJson('/api/v1/tags/'.$tag['id'].'/assignments', [
        'entity_type' => 'contact', 'entity_id' => $source['id'],
    ])->assertCreated()->json('data');
    FileRecord::create([
        'tenant_id' => $client['tenant']->id, 'disk' => 'local', 'path' => 'test/file.pdf',
        'filename' => 'file.pdf', 'mime_type' => 'application/pdf', 'size' => 10,
        'related_type' => 'contact', 'related_id' => $source['id'],
    ]);

    $api->postJson('/api/v1/contacts/duplicate-check', [
        'email' => 'ana@example.com', 'exclude_id' => $target['id'],
    ])->assertOk()->assertJsonPath('data.0.id', $source['id'])->assertJsonPath('data.0.confidence', 'medium');

    $role = Role::query()->where('tenant_id', $client['tenant']->id)->firstOrFail();
    $role->permissions()->sync(Permission::query()->whereIn('key', [
        'contacts.view', 'contacts.update', 'contacts.delete', 'duplicates.manage',
    ])->pluck('id'));
    $this->app['auth']->forgetGuards();
    $merge = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/contacts/'.$target['id'].'/merge', [
            'duplicate_id' => $source['id'],
        ])->assertOk()->assertJsonPath('data.last_name', 'Pérez');
    expect($merge->json('data'))->not->toHaveKey('tags');

    $this->assertSoftDeleted('contacts', ['id' => $source['id'], 'tenant_id' => $client['tenant']->id]);
    $this->assertDatabaseHas('deals', ['id' => $deal['id'], 'contact_id' => $target['id']]);
    $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'related_type' => 'contact', 'related_id' => $target['id']]);
    $this->assertDatabaseHas('file_records', ['filename' => 'file.pdf', 'related_id' => $target['id']]);
    $this->assertDatabaseHas('tag_assignments', ['id' => $assignment['id'], 'taggable_id' => $target['id']]);
    $this->assertDatabaseHas('entity_relations', ['id' => $relation['id'], 'from_id' => (string) $target['id']]);
    $this->assertDatabaseHas('contact_organization', ['contact_id' => $target['id'], 'organization_id' => $company['id']]);
    $this->assertDatabaseHas('record_merges', ['entity_type' => 'contact', 'source_id' => $source['id'], 'target_id' => $target['id']]);
    $this->assertDatabaseHas('activities', ['type' => 'contact.merged', 'activityable_id' => $target['id']]);
});

it('keeps duplicate checks and merges isolated by tenant', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();
    $own = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Own', 'email' => 'same@example.com'])->assertCreated()->json('data');
    $this->app['auth']->forgetGuards();
    $foreign = $this->withToken($other['token'])->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Foreign', 'email' => 'same@example.com'])->assertCreated()->json('data');

    $this->app['auth']->forgetGuards();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/contacts/duplicate-check', ['email' => 'same@example.com', 'exclude_id' => $own['id']])
        ->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/contacts/'.$own['id'].'/merge', ['duplicate_id' => $foreign['id']])
        ->assertUnprocessable()->assertJsonValidationErrors(['duplicate_id']);
});

it('supports company duplicate aliases and preserves contacts during merge', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $target = $api->postJson('/api/v1/organizations', [
        'name' => 'Orbit Holdings', 'website' => 'https://orbit.example.com',
    ])->assertCreated()->json('data');
    $source = $api->postJson('/api/v1/organizations', [
        'name' => 'ORBIT HOLDINGS', 'website' => 'https://www.orbit.example.com',
    ])->assertCreated()->json('data');
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Linked', 'organization_ids' => [$source['id']],
    ])->assertCreated()->json('data');

    $api->postJson('/api/v1/companies/duplicate-check', [
        'website' => 'https://orbit.example.com', 'exclude_id' => $target['id'],
    ])->assertOk()->assertJsonPath('data.0.id', $source['id']);
    $api->postJson('/api/v1/companies/'.$target['id'].'/merge', ['duplicate_id' => $source['id']])
        ->assertOk()->assertJsonPath('data.id', $target['id']);

    $this->assertDatabaseHas('contact_organization', ['contact_id' => $contact['id'], 'organization_id' => $target['id']]);
    $this->assertSoftDeleted('organizations', ['id' => $source['id']]);
});

it('searches authorized resources globally without leaking another tenant', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Nebula', 'last_name' => 'Buyer'])->assertCreated();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/organizations', ['name' => 'Nebula Industries'])->assertCreated();
    $this->app['auth']->forgetGuards();
    $this->withToken($other['token'])->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Nebula', 'last_name' => 'Secret'])->assertCreated();

    $this->app['auth']->forgetGuards();
    $response = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->getJson('/api/v1/search?q=Nebula&types[]=contacts&types[]=companies')
        ->assertOk()->assertJsonPath('meta.total', 2);
    expect(collect($response->json('data'))->pluck('title')->all())
        ->toContain('Nebula Buyer', 'Nebula Industries')->not->toContain('Nebula Secret');

    $restricted = $this->createTenantUser(['contacts.view']);
    $this->app['auth']->forgetGuards();
    $this->withToken($restricted['token'])->withHeader('X-Tenant-ID', (string) $restricted['tenant']->id)
        ->getJson('/api/v1/search?q=Nebula')->assertForbidden();
});

it('stores saved view filters and enforces private and tenant visibility', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $view = $api->postJson('/api/v1/saved-views', [
        'entity_type' => 'contacts', 'name' => 'Active contacts', 'visibility' => 'private',
        'columns' => ['first_name', 'email'], 'sort_field' => 'first_name', 'sort_direction' => 'asc',
        'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'active']],
    ])->assertCreated()->assertJsonPath('data.filters.0.value', 'active')->json('data');
    $api->getJson('/api/v1/saved-views?entity_type=unsupported')
        ->assertUnprocessable()->assertJsonValidationErrors(['entity_type']);

    $role = Role::query()->where('tenant_id', $client['tenant']->id)->firstOrFail();
    $second = User::factory()->create();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id, 'user_id' => $second->id, 'role_id' => $role->id,
        'status' => 'active', 'joined_at' => now(),
    ]);
    $secondToken = $second->createToken('test')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($secondToken)->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->getJson('/api/v1/saved-views/'.$view['id'])->assertNotFound();

    $this->app['auth']->forgetGuards();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->patchJson('/api/v1/saved-views/'.$view['id'], ['visibility' => 'tenant'])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($secondToken)->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->getJson('/api/v1/saved-views/'.$view['id'])
        ->assertOk()->assertJsonPath('data.filters.0.value', 'active');
});

it('creates reusable tags and assigns them idempotently to tenant records', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();
    $contact = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Tagged'])->assertCreated()->json('data');
    $this->app['auth']->forgetGuards();
    $foreign = $this->withToken($other['token'])->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Foreign'])->assertCreated()->json('data');
    $this->app['auth']->forgetGuards();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $tag = $api->postJson('/api/v1/tags', ['name' => ' Priority ', 'color' => '#AABBCC'])
        ->assertCreated()->json('data');
    $payload = ['entity_type' => 'contact', 'entity_id' => $contact['id']];
    $assignment = $api->postJson('/api/v1/tags/'.$tag['id'].'/assignments', $payload)
        ->assertCreated()->assertJsonPath('meta.created', true)->json('data');
    $api->postJson('/api/v1/tags/'.$tag['id'].'/assignments', $payload)
        ->assertOk()->assertJsonPath('meta.created', false)->assertJsonPath('data.id', $assignment['id']);
    $api->getJson('/api/v1/contacts/'.$contact['id'])->assertOk()->assertJsonPath('data.tags.0.name', 'Priority');
    $api->postJson('/api/v1/tags/'.$tag['id'].'/assignments', [
        'entity_type' => 'contact', 'entity_id' => $foreign['id'],
    ])->assertUnprocessable();

    $restrictedRole = Role::create([
        'tenant_id' => $client['tenant']->id, 'name' => 'Tag manager without contacts', 'is_system' => false,
    ]);
    $restrictedRole->permissions()->sync(Permission::query()->whereIn('key', ['tags.view', 'tags.manage'])->pluck('id'));
    $restrictedUser = User::factory()->create();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id, 'user_id' => $restrictedUser->id, 'role_id' => $restrictedRole->id,
        'status' => 'active', 'joined_at' => now(),
    ]);
    $this->app['auth']->forgetGuards();
    $this->withToken($restrictedUser->createToken('test')->plainTextToken)
        ->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->patchJson('/api/v1/tags/'.$tag['id'], ['description' => 'Restricted count'])
        ->assertOk()->assertJsonPath('data.assignments_count', 0);

    $this->app['auth']->forgetGuards();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->deleteJson('/api/v1/tags/'.$tag['id'].'/assignments/'.$assignment['id'])->assertOk();
    $this->assertDatabaseMissing('tag_assignments', ['id' => $assignment['id']]);
});

it('reports only the fields an update actually changed in audit timeline events', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@acme.test', 'phone' => '+593 99 111 1111',
    ])->assertCreated()->json('data');

    $this->travel(1)->minutes();
    $api->patchJson('/api/v1/contacts/'.$contact['id'], ['phone' => '+593 99 222 2222'])->assertOk();

    $events = $api->getJson('/api/v1/contacts/'.$contact['id'].'/timeline?source=audit&event=audit.update')
        ->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($events[0]['metadata']['changed_fields'])
        ->toEqualCanonicalizing(['phone', 'phone_normalized', 'updated_at']);
});

it('ignores representation-only differences between audit snapshots', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $contact = $api->postJson('/api/v1/contacts', ['first_name' => 'Ada'])->assertCreated()->json('data');

    app(AuditService::class)->record('update', 'contacts', $contact['id'], tenantId: $client['tenant']->id, oldValues: [
        'first_name' => 'Ada', 'owner_id' => 7, 'active' => 1,
        'custom_fields' => '{"tier": "gold", "ruc": "1790011223001"}', 'phone' => '0991111111',
        'settings' => '{"code": "0912"}',
    ], newValues: [
        'first_name' => 'Ada', 'owner_id' => '7', 'active' => true,
        'custom_fields' => '{"ruc":"1790011223001","tier":"gold"}', 'phone' => '991111111',
        'settings' => '{"code":"912"}',
    ]);

    $events = $api->getJson('/api/v1/contacts/'.$contact['id'].'/timeline?source=audit&event=audit.update')
        ->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($events[0]['metadata']['changed_fields'])->toBe(['phone', 'settings']);
});

it('does not report untouched snapshot keys when an audit entry stores partial new values', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $contact = $api->postJson('/api/v1/contacts', ['first_name' => 'Ada'])->assertCreated()->json('data');

    app(AuditService::class)->record('merge', 'contacts', $contact['id'], tenantId: $client['tenant']->id, oldValues: [
        'id' => $contact['id'], 'first_name' => 'Ada', 'status' => 'active',
    ], newValues: ['source_id' => 99, 'status' => 'active']);

    $events = $api->getJson('/api/v1/contacts/'.$contact['id'].'/timeline?source=audit&event=audit.merge')
        ->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($events[0]['metadata']['changed_fields'])->toBe(['source_id']);
});
