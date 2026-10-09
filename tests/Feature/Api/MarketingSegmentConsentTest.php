<?php

use App\Jobs\ProcessMarketingJob;
use App\Models\ConsentLink;
use App\Models\Contact;
use App\Models\EntityDefinition;
use App\Models\EntityRecord;
use App\Models\FieldDefinition;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\ConsentService;
use App\Services\SegmentEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('evaluates nested tenant scoped segments and replaces snapshots after definition changes', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $foreign = Tenant::factory()->create();
    $ada = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'first_name' => 'Ada', 'last_name' => 'Exact_100%', 'status' => 'active', 'phone' => null]);
    Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'first_name' => 'Grace', 'status' => 'inactive']);
    Contact::factory()->create(['tenant_id' => $foreign->id, 'first_name' => 'Ada', 'status' => 'active']);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $definition = ['operator' => 'and', 'conditions' => [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ['operator' => 'or', 'conditions' => [
            ['field' => 'first_name', 'operator' => 'equals', 'value' => 'Ada'],
            ['field' => 'phone', 'operator' => 'is_empty'],
        ]],
    ]];
    $api->postJson('/api/v1/segments/preview', ['entity_type' => 'contacts', 'definition' => $definition])
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ada->id);
    $api->postJson('/api/v1/segments/preview', [
        'entity_type' => 'contacts', 'definition' => ['field' => 'last_name', 'operator' => 'contains', 'value' => '_100%'],
    ])->assertOk()->assertJsonPath('meta.total', 1);
    $segment = $api->postJson('/api/v1/segments', [
        'name' => 'Contactos interesados', 'entity_type' => 'contacts', 'definition' => $definition,
    ])->assertCreated()->assertJsonMissingPath('data.slug')->json('data');
    $api->postJson('/api/v1/segments/'.$segment['id'].'/refresh')->assertStatus(202);
    Queue::assertPushed(ProcessMarketingJob::class, fn ($job) => $job->kind === 'segment' && $job->recordId === $segment['id']);
    (new ProcessMarketingJob($client['tenant']->id, 'segment', $segment['id']))->handle(app(TenantContext::class));
    expect(app(TenantContext::class)->id())->toBeNull();
    $api->getJson('/api/v1/segments/'.$segment['id'].'/members')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.entity_id', $ada->id);
    $api->patchJson('/api/v1/segments/'.$segment['id'], [
        'definition' => ['field' => 'first_name', 'operator' => 'not_equals', 'value' => 'Ada'],
    ])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.refreshed_at', null);
    $api->getJson('/api/v1/segments/'.$segment['id'].'/members')->assertOk()->assertJsonPath('meta.total', 0);
    (new ProcessMarketingJob($client['tenant']->id, 'segment', $segment['id']))->handle(app(TenantContext::class));
    $api->getJson('/api/v1/segments/'.$segment['id'].'/members')->assertOk()->assertJsonPath('meta.total', 1);
});

it('supports numeric and date filters on leads companies deals and declared custom object fields', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $context = app(TenantContext::class);
    $context->set($client['tenant']->id);
    Lead::create(['first_name' => 'Hot', 'score' => 100, 'capture_origin' => 'form', 'utm_source' => 'newsletter']);
    Lead::create(['first_name' => 'Cold', 'score' => 9]);
    Organization::create(['name' => 'Analytical', 'industry' => 'Technology']);
    $definition = EntityDefinition::create(['name' => 'subscriptions', 'label' => 'Subscriptions', 'active' => true]);
    FieldDefinition::create(['entity_definition_id' => $definition->id, 'name' => 'amount', 'label' => 'Amount', 'type' => 'decimal', 'active' => true]);
    FieldDefinition::create(['entity_definition_id' => $definition->id, 'name' => 'renewal', 'label' => 'Renewal', 'type' => 'date', 'active' => true]);
    EntityRecord::create(['entity_definition_id' => $definition->id, 'data' => ['amount' => '100.50', 'renewal' => '2026-10-01']]);
    EntityRecord::create(['entity_definition_id' => $definition->id, 'data' => ['amount' => '9.50', 'renewal' => '2026-01-01']]);
    $engine = app(SegmentEngine::class);
    expect($engine->query('leads', ['field' => 'score', 'operator' => 'greater_than', 'value' => 10])->count())->toBe(1)
        ->and($engine->query('leads', ['field' => 'capture_origin', 'operator' => 'equals', 'value' => 'form'])->count())->toBe(1)
        ->and($engine->query('leads', ['field' => 'utm_source', 'operator' => 'equals', 'value' => 'newsletter'])->count())->toBe(1)
        ->and($engine->query('companies', ['field' => 'industry', 'operator' => 'equals', 'value' => 'Technology'])->count())->toBe(1)
        ->and($engine->query('custom_objects', ['field' => 'data.amount', 'operator' => 'greater_than', 'value' => '10'], $definition->id)->count())->toBe(1)
        ->and($engine->query('custom_objects', ['field' => 'data.renewal', 'operator' => 'after', 'value' => '2026-09-01'], $definition->id)->count())->toBe(1)
        ->and($engine->query('custom_objects', ['field' => 'data.renewal', 'operator' => 'equals', 'value' => '2026-10-01'], $definition->id)->count())->toBe(1);
    $context->clear();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $api->getJson('/api/v1/segments/fields?entity_type=opportunities')->assertOk()->assertJsonPath('data.value', 'number');
    $api->getJson('/api/v1/segments/fields?entity_type=leads')->assertOk()
        ->assertJsonPath('data.capture_origin', 'text')->assertJsonPath('data.converted_at', 'datetime')
        ->assertJsonMissingPath('data.origin')->assertJsonMissingPath('data.company_name');
    $pipeline = $api->postJson('/api/v1/pipelines', ['name' => 'Marketing sales', 'entity_type' => 'deals', 'stages' => [['name' => 'New', 'position' => 0]]])->assertCreated()->json('data');
    $api->postJson('/api/v1/deals', [
        'name' => 'Attributed opportunity', 'pipeline_id' => $pipeline['id'], 'stage_id' => $pipeline['stages'][0]['id'],
        'value' => '1234.56', 'currency' => 'usd', 'expected_close_date' => '2026-12-01',
    ])->assertCreated();
    $api->postJson('/api/v1/segments/preview', [
        'entity_type' => 'opportunities', 'definition' => ['operator' => 'and', 'conditions' => [
            ['field' => 'value', 'operator' => 'less_than', 'value' => '2000'],
            ['field' => 'expected_close_date', 'operator' => 'before', 'value' => '2027-01-01'],
        ]],
    ])->assertOk()->assertJsonPath('meta.total', 1);
});

it('rejects unsafe filters unknown custom fields invalid types and excessive nesting', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $invalid = [
        ['field' => 'email) OR 1=1 --', 'operator' => 'equals', 'value' => 'x'],
        ['field' => 'tenant_id', 'operator' => 'equals', 'value' => 1],
        ['field' => 'custom_fields.undeclared', 'operator' => 'equals', 'value' => 'x'],
        ['field' => 'email', 'operator' => 'raw', 'value' => '1=1'],
        ['field' => 'email', 'operator' => 'equals', 'value' => ['nested']],
        ['field' => 'email', 'operator' => 'greater_than', 'value' => 'x'],
        ['field' => 'created_at', 'operator' => 'after', 'value' => 'tomorrow'],
        ['operator' => 'or', 'conditions' => []],
    ];
    $deep = ['field' => 'id', 'operator' => 'equals', 'value' => 1];
    for ($i = 0; $i < 7; $i++) {
        $deep = ['operator' => 'and', 'conditions' => [$deep]];
    }
    $invalid[] = $deep;
    foreach ($invalid as $definition) {
        $api->postJson('/api/v1/segments/preview', ['entity_type' => 'contacts', 'definition' => $definition])
            ->assertUnprocessable()->assertJsonValidationErrors('definition');
    }
    $foreign = $this->createTenantUser();
    $custom = EntityDefinition::create(['tenant_id' => $foreign['tenant']->id, 'name' => 'private_objects', 'label' => 'Private', 'active' => true]);
    $api->postJson('/api/v1/segments/preview', [
        'entity_type' => 'custom_objects', 'entity_definition_id' => $custom->id,
        'definition' => ['field' => 'id', 'operator' => 'is_not_empty'],
    ])->assertUnprocessable()->assertJsonValidationErrors('entity_definition_id');
});

it('keeps static and dynamic audience membership tenant safe and protects referenced segments', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id]);
    $foreignContact = Contact::factory()->create();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $segment = $api->postJson('/api/v1/segments', ['name' => 'All contacts', 'entity_type' => 'contacts', 'definition' => ['field' => 'id', 'operator' => 'greater_than', 'value' => 0]])->assertCreated()->json('data');
    $dynamic = $api->postJson('/api/v1/audiences', ['name' => 'Dynamic', 'type' => 'dynamic', 'entity_type' => 'contacts', 'segment_id' => $segment['id']])->assertCreated()->json('data');
    $api->postJson('/api/v1/audiences/'.$dynamic['id'].'/members', ['entity_ids' => [$contact->id]])->assertConflict();
    $api->deleteJson('/api/v1/segments/'.$segment['id'])->assertConflict();
    $api->postJson('/api/v1/audiences/'.$dynamic['id'].'/refresh')->assertStatus(202);
    (new ProcessMarketingJob($client['tenant']->id, 'audience', $dynamic['id']))->handle(app(TenantContext::class));
    $api->getJson('/api/v1/audiences/'.$dynamic['id'].'/members')->assertOk()->assertJsonPath('meta.total', 1);
    $static = $api->postJson('/api/v1/audiences', ['name' => 'Static', 'type' => 'static', 'entity_type' => 'contacts'])->assertCreated()->json('data');
    $api->postJson('/api/v1/audiences/'.$static['id'].'/members', ['entity_ids' => [$contact->id, $foreignContact->id]])->assertUnprocessable();
    $api->getJson('/api/v1/audiences/'.$static['id'].'/members')->assertOk()->assertJsonPath('meta.total', 0);
    foreach ([1, 2] as $attempt) {
        $api->postJson('/api/v1/audiences/'.$static['id'].'/members', ['entity_ids' => [$contact->id]])->assertOk()->assertJsonPath('data.members_count', 1);
    }
    $api->deleteJson('/api/v1/audiences/'.$static['id'].'/members', ['entity_ids' => [$contact->id]])->assertOk()->assertJsonPath('data.members_count', 0);
    $reader = $this->createTenantUser(['segments.view', 'audiences.view']);
    Sanctum::actingAs($reader['user']);
    $this->withHeader('X-Tenant-ID', $reader['tenant']->id)->getJson('/api/v1/segments/'.$segment['id'])->assertNotFound();
    $this->postJson('/api/v1/segments', ['name' => 'Forbidden'])->assertForbidden();
    $this->postJson('/api/v1/audiences/'.$static['id'].'/refresh')->assertForbidden();
});

it('records append only channel consents with evidence and idempotent revocation shared by destination', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'email' => 'consent@example.com', 'phone' => '+593999111222']);
    $duplicate = Lead::create(['tenant_id' => $client['tenant']->id, 'first_name' => 'Duplicate', 'email' => 'CONSENT@example.com']);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    foreach (['email', 'sms', 'whatsapp'] as $channel) {
        $payload = ['entity_type' => 'contacts', 'entity_id' => $contact->id, 'channel' => $channel,
            'status' => 'opt_in', 'source' => 'signed_form', 'evidence' => 'Accepted checkbox, form version 2.', 'idempotency_key' => 'optin-'.$channel];
        $first = $api->postJson('/api/v1/consents', $payload)->assertCreated()->assertJsonMissingPath('data.payload_hash')->json('data');
        $api->postJson('/api/v1/consents', $payload)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $api->postJson('/api/v1/consents', array_replace($payload, ['status' => 'opt_out']))->assertConflict();
    }
    $api->getJson('/api/v1/consents/preferences?entity_type=contacts&entity_id='.$contact->id)->assertOk()->assertJsonPath('data.email', 'opt_in')->assertJsonPath('data.sms', 'opt_in')->assertJsonPath('data.whatsapp', 'opt_in');
    $api->postJson('/api/v1/consents', ['entity_type' => 'contacts', 'entity_id' => $contact->id, 'channel' => 'email', 'status' => 'opt_in', 'source' => 'unknown', 'idempotency_key' => 'no-evidence'])
        ->assertUnprocessable()->assertJsonValidationErrors('evidence');
    $api->postJson('/api/v1/consents', ['entity_type' => 'leads', 'entity_id' => $duplicate->id, 'channel' => 'email', 'status' => 'opt_out', 'source' => 'unsubscribe', 'idempotency_key' => 'revoke-email'])
        ->assertCreated()->assertJsonPath('data.status', 'opt_out');
    $api->getJson('/api/v1/consents/preferences?entity_type=contacts&entity_id='.$contact->id)->assertOk()->assertJsonPath('data.email', 'opt_out');
    $records = $api->getJson('/api/v1/consents?entity_type=contacts&entity_id='.$contact->id)->assertOk()->assertJsonPath('meta.total', 3)->json('data');
    expect($records[0]['ip'])->toBe('127.0.0.1')->and($records[0]['occurred_at'])->not->toBeNull();
    $this->assertDatabaseCount('consent_records', 4);
});

it('serves private bearer preference links and only allows revocation of pinned destinations', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'email' => 'before@example.com']);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $api->postJson('/api/v1/consents', ['entity_type' => 'contacts', 'entity_id' => $contact->id, 'channel' => 'email', 'status' => 'opt_in', 'source' => 'web', 'evidence' => 'Explicit checkbox', 'idempotency_key' => 'initial'])->assertCreated();
    $link = $api->postJson('/api/v1/consent-links', ['entity_type' => 'contacts', 'entity_id' => $contact->id])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    $public = '/api/v1/public/preferences/'.$link['token'];
    $this->getJson($public)->assertOk()->assertJsonPath('data.preferences.email', 'opt_in')->assertJsonMissingPath('data.entity_id')->assertJsonMissingPath('data.token_hash')->assertJsonMissingPath('data.destination');
    $this->postJson($public, ['preferences' => ['email' => 'opt_in'], 'idempotency_key' => 'public-grant'])->assertUnprocessable();
    $this->postJson($public, ['preferences' => ['email' => 'opt_out'], 'idempotency_key' => 'public-revoke'])->assertOk()->assertJsonPath('data.preferences.email', 'opt_out');
    $this->postJson($public, ['preferences' => ['email' => 'opt_out'], 'idempotency_key' => 'public-revoke'])->assertOk();
    $this->assertDatabaseCount('consent_records', 2);
    app(TenantContext::class)->set($client['tenant']->id);
    expect(ConsentLink::findOrFail($link['id'])->token_hash)->toBe(hash('sha256', $link['token']));
    $contact->update(['email' => 'after@example.com']);
    expect(app(ConsentService::class)->allowed($contact->fresh(), 'email'))->toBeFalse();
    app(TenantContext::class)->clear();
    $this->postJson($public, ['preferences' => ['email' => 'opt_out'], 'idempotency_key' => 'old-destination'])->assertOk();
    $this->assertDatabaseMissing('consent_records', ['destination' => 'after@example.com']);
    $api->deleteJson('/api/v1/consent-links/'.$link['id'])->assertOk();
    $this->getJson($public)->assertNotFound();
    $this->getJson('/api/v1/public/preferences/'.str_repeat('x', 64))->assertNotFound();
});

it('renders an actionable preference page without changing consent on GET and supports form revocation', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $link = $api->postJson('/api/v1/consent-links', ['entity_type' => 'contacts', 'entity_id' => $contact->id])->assertCreated()->json('data');
    $path = '/api/v1/public/preferences/'.$link['token'];
    $this->withHeader('Accept', 'text/html')->get($path)->assertOk()->assertSee('Confirmar baja')->assertHeader('X-Frame-Options', 'DENY');
    $this->assertDatabaseCount('consent_records', 0);
    $this->post($path, ['preferences' => ['email' => 'opt_out'], 'idempotency_key' => 'browser-revoke'])
        ->assertOk()->assertSee('Tu solicitud de baja se ha registrado.');
    $this->assertDatabaseHas('consent_records', ['entity_id' => $contact->id, 'channel' => 'email', 'status' => 'opt_out']);
    $this->travel(91)->days();
    $this->getJson($path)->assertNotFound();
    $this->travelBack();
});
