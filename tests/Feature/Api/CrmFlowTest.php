<?php

use App\Jobs\RunAutomationJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\EntityDefinition;
use App\Models\Pipeline;
use App\Services\WorkflowEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('converts a lead to a contact and deal atomically', function (): void {
    $client = $this->createTenantUser();
    $pipeline = Pipeline::create([
        'tenant_id' => $client['tenant']->id,
        'name' => 'Sales',
        'is_default' => true,
        'active' => true,
    ]);
    $stage = $pipeline->stages()->create([
        'name' => 'New',
        'position' => 1,
        'probability' => 10,
    ]);
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $lead = $request->postJson('/api/v1/leads', [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'source' => 'web',
    ])->assertCreated()->json('data');

    $response = $request->postJson('/api/v1/leads/'.$lead['id'].'/convert', [
        'create_contact' => true,
        'deal' => [
            'name' => 'Ada opportunity',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'value' => 2500,
            'currency' => 'USD',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.lead.status', 'converted')
        ->assertJsonPath('data.contact.email', 'ada@example.com')
        ->assertJsonPath('data.deal.name', 'Ada opportunity');
    $this->assertDatabaseHas('deals', ['tenant_id' => $client['tenant']->id, 'name' => 'Ada opportunity']);
});

it('creates and updates a deal only with a stage in the selected pipeline', function (): void {
    $client = $this->createTenantUser();
    $pipeline = Pipeline::create(['tenant_id' => $client['tenant']->id, 'name' => 'Sales', 'active' => true]);
    $stage = $pipeline->stages()->create(['name' => 'New', 'position' => 1, 'probability' => 10]);
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $deal = $request->postJson('/api/v1/deals', [
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'name' => 'First deal',
        'value' => 500,
        'currency' => 'USD',
    ])->assertCreated()->json('data');

    $request->patchJson('/api/v1/deals/'.$deal['id'], ['name' => 'Renamed'])->assertOk();
});

it('updates pipeline stages by numeric id', function (): void {
    $client = $this->createTenantUser();
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $pipeline = $request->postJson('/api/v1/pipelines', [
        'name' => 'Enterprise sales',
        'stages' => [[
            'name' => 'Qualified',
            'position' => 1,
            'probability' => 20,
        ]],
    ])->assertCreated()->json('data');

    $stageId = $pipeline['stages'][0]['id'];
    $request->patchJson('/api/v1/pipelines/'.$pipeline['id'], [
        'stages' => [[
            'id' => $stageId,
            'name' => 'Sales qualified',
            'position' => 1,
            'probability' => 40,
        ]],
    ])->assertOk()->assertJsonPath('data.stages.0.id', $stageId);

    $this->assertDatabaseHas('pipeline_stages', [
        'id' => $stageId,
        'name' => 'Sales qualified',
        'probability' => 40,
    ]);
});

it('stores a custom entity record using its field definitions', function (): void {
    $client = $this->createTenantUser();
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $definition = $request->postJson('/api/v1/entity-definitions', [
        'name' => 'Vehicle',
        'label' => 'Vehicle',
    ])->assertCreated()->json('data');
    $request->postJson('/api/v1/field-definitions', [
        'entity_definition_id' => $definition['id'],
        'name' => 'plate',
        'label' => 'Plate',
        'type' => 'text',
        'required' => true,
    ])->assertCreated();

    $request->postJson('/api/v1/entities/'.$definition['id'].'/records', ['data' => ['plate' => 'ABC-123']])
        ->assertCreated()
        ->assertJsonPath('data.data.plate', 'ABC-123');

    $request->getJson('/api/v1/entities/'.$definition['id'].'/records?filter[plate][operator]=eq&filter[plate][value]=ABC-123')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('rejects a custom entity definition from another tenant', function (): void {
    $client = $this->createTenantUser();
    $otherClient = $this->createTenantUser();
    $otherDefinition = EntityDefinition::create([
        'tenant_id' => $otherClient['tenant']->id,
        'name' => 'External asset',
        'label' => 'External asset',
    ]);
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $request->postJson('/api/v1/field-definitions', [
        'entity_definition_id' => $otherDefinition->id,
        'name' => 'serial_number',
        'label' => 'Serial number',
        'type' => 'text',
    ])->assertUnprocessable()->assertJsonValidationErrors(['entity_definition_id']);
});

it('runs a validated automation in its tenant queue context', function (): void {
    $client = $this->createTenantUser();
    $automation = Automation::create([
        'tenant_id' => $client['tenant']->id,
        'name' => 'Follow up new contacts',
        'event_type' => 'contact.created',
        'config' => [
            'trigger' => ['type' => 'contact.created'],
            'conditions' => [],
            'actions' => [['type' => 'create_task', 'title' => 'Follow up contact']],
        ],
        'active' => true,
    ]);
    $contact = Contact::create(['tenant_id' => $client['tenant']->id, 'first_name' => 'Grace']);

    app(RunAutomationJob::class, [
        'tenantId' => $client['tenant']->id,
        'automationId' => $automation->id,
        'eventType' => 'contact.created',
        'eventId' => 'test-event-1',
        'modelType' => Contact::class,
        'modelId' => $contact->id,
    ])->handle(app(WorkflowEngine::class));

    $this->assertDatabaseHas('tasks', ['tenant_id' => $client['tenant']->id, 'title' => 'Follow up contact']);
    expect(app(TenantContext::class)->id())->toBeNull();
});

it('does not let a read-only custom entity user create records', function (): void {
    $client = $this->createTenantUser(['custom_entities.view']);
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $definition = EntityDefinition::create(['tenant_id' => $client['tenant']->id, 'name' => 'Property', 'label' => 'Property']);

    $request->postJson('/api/v1/entities/'.$definition->id.'/records', ['data' => ['blocked' => true]])->assertForbidden();
});
