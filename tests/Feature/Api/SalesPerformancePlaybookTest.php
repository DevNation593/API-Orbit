<?php

use App\Jobs\RunAutomationJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\PlaybookActionLog;
use App\Models\Quote;
use App\Models\Task;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('manages hierarchical branches, teams and territories without cycles', function (): void {
    $client = $this->createTenantUser();
    $member = User::factory()->create();
    $membership = TenantUser::query()->where('tenant_id', $client['tenant']->id)->where('user_id', $client['user']->id)->sole();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id,
        'user_id' => $member->id,
        'role_id' => $membership->role_id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $parentBranch = $api->postJson('/api/v1/branches', [
        'code' => 'ec', 'name' => 'Ecuador', 'timezone' => 'America/Guayaquil',
    ])->assertCreated()->assertJsonPath('data.code', 'EC')->json('data');
    $childBranch = $api->postJson('/api/v1/branches', [
        'parent_id' => $parentBranch['id'], 'code' => 'uio', 'name' => 'Quito',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/branches/'.$parentBranch['id'], ['parent_id' => $childBranch['id']])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    $api->deleteJson('/api/v1/branches/'.$parentBranch['id'])
        ->assertUnprocessable()->assertJsonValidationErrors(['record']);

    $team = $api->postJson('/api/v1/sales-teams', [
        'branch_id' => $childBranch['id'], 'name' => 'Enterprise',
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/sales-teams/'.$team['id'].'/members', [
        'user_id' => $member->id, 'role' => 'member', 'quota_weight' => '0.500000',
    ])->assertCreated()->assertJsonPath('data.role', 'member');
    $api->patchJson('/api/v1/sales-teams/'.$team['id'].'/members/'.$member->id, [
        'role' => 'manager', 'quota_weight' => '1.000000',
    ])->assertOk()->assertJsonPath('data.role', 'manager');
    $this->assertDatabaseCount('sales_team_members', 1);

    $parentTerritory = $api->postJson('/api/v1/territories', [
        'branch_id' => $childBranch['id'], 'code' => 'EC', 'name' => 'Ecuador', 'type' => 'geographic',
    ])->assertCreated()->json('data');
    $childTerritory = $api->postJson('/api/v1/territories', [
        'parent_id' => $parentTerritory['id'], 'branch_id' => $childBranch['id'],
        'code' => 'EC-UIO', 'name' => 'Quito', 'type' => 'geographic',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/territories/'.$parentTerritory['id'], ['parent_id' => $childTerritory['id']])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    $api->postJson('/api/v1/territories/'.$childTerritory['id'].'/members', [
        'user_id' => $member->id, 'role' => 'manager', 'capacity' => 20,
    ])->assertCreated()->assertJsonPath('data.capacity', 20);
});

it('evaluates territory rules and keeps assignments inside the active tenant', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $territory = $api->postJson('/api/v1/territories', [
        'code' => 'TECH', 'name' => 'Technology accounts', 'type' => 'account',
    ])->assertCreated()->json('data');
    $organization = $api->postJson('/api/v1/organizations', [
        'name' => 'Analytical Engines', 'industry' => 'Technology',
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/territory-rules', [
        'territory_id' => $territory['id'], 'name' => 'Technology organizations',
        'entity_type' => 'organization', 'priority' => 10,
        'conditions' => [['field' => 'industry', 'operator' => 'eq', 'value' => 'technology']],
    ])->assertCreated();

    $assignment = $api->postJson('/api/v1/territory-assignments', [
        'entity_type' => 'organization', 'entity_id' => $organization['id'], 'evaluate_rules' => true,
    ])->assertCreated()
        ->assertJsonPath('data.entity.territory_id', $territory['id'])
        ->assertJsonPath('data.assignment.source', 'rule')
        ->json('data.assignment');
    $api->deleteJson('/api/v1/territory-assignments/'.$assignment['id'])->assertOk();
    $this->assertDatabaseHas('organizations', ['id' => $organization['id'], 'territory_id' => null]);

    $foreign = $this->createTenantUser();
    $foreignOrganization = Contact::create([
        'tenant_id' => $foreign['tenant']->id, 'first_name' => 'Foreign',
    ]);
    $api->postJson('/api/v1/territory-assignments', [
        'entity_type' => 'contact', 'entity_id' => $foreignOrganization->id,
        'territory_id' => $territory['id'],
    ])->assertNotFound();
});

it('calculates goal progress, forecast and sales analytics with exact decimals', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $branch = $api->postJson('/api/v1/branches', ['code' => 'HQ', 'name' => 'Headquarters'])->assertCreated()->json('data');
    $team = $api->postJson('/api/v1/sales-teams', [
        'branch_id' => $branch['id'], 'name' => 'Direct sales',
    ])->assertCreated()->json('data');
    $territory = $api->postJson('/api/v1/territories', [
        'branch_id' => $branch['id'], 'code' => 'NORTH', 'name' => 'North', 'type' => 'geographic',
    ])->assertCreated()->json('data');
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'owner_id' => $client['user']->id,
    ])->assertCreated()->json('data');
    $organization = $api->postJson('/api/v1/organizations', [
        'name' => 'Analytical Engines', 'owner_id' => $client['user']->id,
        'territory_id' => $territory['id'], 'industry' => 'Technology',
    ])->assertCreated()->json('data');
    Lead::create([
        'tenant_id' => $client['tenant']->id, 'first_name' => 'Ada', 'source' => 'website',
        'owner_id' => $client['user']->id, 'territory_id' => $territory['id'],
        'contact_id' => $contact['id'], 'organization_id' => $organization['id'],
        'status' => 'converted', 'converted_at' => now(),
    ]);
    $pipeline = $api->postJson('/api/v1/pipelines', [
        'name' => 'Revenue',
        'stages' => [
            ['name' => 'Qualified', 'position' => 1, 'probability' => 25],
            ['name' => 'Won', 'position' => 2, 'probability' => 100, 'is_won' => true],
            ['name' => 'Lost', 'position' => 3, 'probability' => 0, 'is_lost' => true],
        ],
    ])->assertCreated()->json('data');
    $stages = collect($pipeline['stages'])->keyBy('name');
    $baseDeal = [
        'pipeline_id' => $pipeline['id'], 'owner_id' => $client['user']->id,
        'sales_team_id' => $team['id'], 'territory_id' => $territory['id'],
        'contact_id' => $contact['id'], 'organization_id' => $organization['id'], 'currency' => 'usd',
    ];
    $api->postJson('/api/v1/deals', [
        ...$baseDeal, 'stage_id' => $stages['Qualified']['id'], 'name' => 'Committed',
        'value' => '100.00', 'forecast_category' => 'commit', 'expected_close_date' => today()->toDateString(),
    ])->assertCreated()
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.pipeline.id', $pipeline['id'])
        ->assertJsonPath('data.stage.id', $stages['Qualified']['id'])
        ->assertJsonPath('data.team.id', $team['id'])
        ->assertJsonPath('data.branch.id', $branch['id'])
        ->assertJsonPath('data.territory.id', $territory['id']);
    $api->postJson('/api/v1/deals', [
        ...$baseDeal, 'stage_id' => $stages['Qualified']['id'], 'name' => 'Best case',
        'value' => '200.00', 'forecast_category' => 'best_case', 'expected_close_date' => today()->toDateString(),
    ])->assertCreated();
    $wonDeal = $api->postJson('/api/v1/deals', [
        ...$baseDeal, 'stage_id' => $stages['Won']['id'], 'name' => 'Won deal',
        'value' => '300.00', 'status' => 'won', 'expected_close_date' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.forecast_category', 'closed')->json('data');
    $api->postJson('/api/v1/deals', [
        ...$baseDeal, 'stage_id' => $stages['Lost']['id'], 'name' => 'Lost deal',
        'value' => '50.00', 'status' => 'lost', 'expected_close_date' => today()->toDateString(),
    ])->assertCreated();

    $period = ['starts_at' => today()->startOfMonth()->toDateString(), 'ends_at' => today()->endOfMonth()->toDateString()];
    $tenantGoal = $api->postJson('/api/v1/goals', [
        'currency_id' => $currency['id'], 'name' => 'Monthly revenue', 'metric' => 'revenue',
        'period_type' => 'monthly', ...$period, 'status' => 'active',
        'targets' => [['target_type' => 'tenant', 'target_value' => '1000']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/goals', [
        'currency_id' => $currency['id'], 'name' => 'Seller revenue', 'metric' => 'revenue',
        'period_type' => 'monthly', ...$period, 'status' => 'active',
        'targets' => [['target_type' => 'user', 'target_id' => $client['user']->id, 'target_value' => '500']],
    ])->assertCreated();
    $api->postJson('/api/v1/goals', [
        'currency_id' => $currency['id'], 'name' => 'Team revenue', 'metric' => 'revenue',
        'period_type' => 'monthly', ...$period, 'status' => 'active',
        'targets' => [['target_type' => 'team', 'target_id' => $team['id'], 'target_value' => '600']],
    ])->assertCreated();
    $api->postJson('/api/v1/goals/'.$tenantGoal['id'].'/refresh')
        ->assertOk()
        ->assertJsonPath('data.targets.0.progress.0.actual_value', '300.000000')
        ->assertJsonPath('data.targets.0.progress.0.completion_percentage', '30.000000');

    $api->getJson('/api/v1/forecast?starts_at='.$period['starts_at'].'&ends_at='.$period['ends_at'])
        ->assertOk()
        ->assertJsonPath('data.pipeline', '300.000000')
        ->assertJsonPath('data.weighted_pipeline', '75.000000')
        ->assertJsonPath('data.commit', '100.000000')
        ->assertJsonPath('data.best_case', '200.000000')
        ->assertJsonPath('data.closed_won', '300.000000')
        ->assertJsonPath('data.target', '1000.000000')
        ->assertJsonPath('data.coverage', '0.300000');
    $api->getJson('/api/v1/forecast/users?starts_at='.$period['starts_at'].'&ends_at='.$period['ends_at'])
        ->assertOk()->assertJsonPath('data.0.target', '500.000000');
    $api->getJson('/api/v1/forecast/teams?starts_at='.$period['starts_at'].'&ends_at='.$period['ends_at'])
        ->assertOk()->assertJsonPath('data.0.target', '600.000000');

    $futureWon = $api->postJson('/api/v1/deals', [
        ...$baseDeal, 'stage_id' => $stages['Won']['id'], 'name' => 'Future close',
        'value' => '400.00', 'status' => 'won', 'expected_close_date' => today()->toDateString(),
    ])->assertCreated()->json('data');
    DB::table('deals')->where('id', $futureWon['id'])->update(['closed_at' => now()->addDay()]);
    $api->getJson('/api/v1/forecast?starts_at='.$period['starts_at'].'&ends_at='.$period['ends_at'].'&as_of_date='.today()->toDateString())
        ->assertOk()->assertJsonPath('data.closed_won', '300.000000');
    $api->postJson('/api/v1/forecast/snapshots', [
        ...$period, 'as_of_date' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.closed_won', '300.000000');
    $api->getJson('/api/v1/forecast/snapshots')->assertOk()->assertJsonPath('meta.total', 1);
    DB::table('deals')->where('id', $futureWon['id'])->update(['deleted_at' => now()]);

    $api->postJson('/api/v1/activities', ['type' => 'call', 'subject' => 'Discovery call'])
        ->assertCreated()->assertJsonPath('data.type', 'call');
    $callsGoal = $api->postJson('/api/v1/goals', [
        'name' => 'Monthly calls', 'metric' => 'calls', 'period_type' => 'monthly', ...$period,
        'status' => 'active', 'targets' => [['target_type' => 'tenant', 'target_value' => '10']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/goals/'.$callsGoal['id'].'/refresh')
        ->assertOk()->assertJsonPath('data.targets.0.progress.0.actual_value', '1.000000');

    $product = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'service', 'sku' => 'ANALYTICS',
        'name' => 'Analytics service', 'base_price' => '50',
    ])->assertCreated()->json('data');
    $quote = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'], 'deal_id' => $wonDeal['id'],
        'contact_id' => $contact['id'], 'organization_id' => $organization['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => '1']],
    ])->assertCreated()->json('data');
    Quote::query()->whereKey($quote['id'])->update(['status' => 'accepted', 'accepted_at' => now()]);

    $analytics = $api->getJson('/api/v1/sales-analytics?starts_at='.$period['starts_at'].'&ends_at='.$period['ends_at'])
        ->assertOk()
        ->assertJsonPath('data.metrics.win_rate', '50.000000')
        ->assertJsonPath('data.metrics.loss_rate', '50.000000')
        ->assertJsonPath('data.metrics.average_deal_size', '300.000000')
        ->json('data');
    expect(collect($analytics['revenue']['by_owner'])->firstWhere('id', $client['user']->id)['value'])->toBe('300.000000')
        ->and(collect($analytics['revenue']['by_product'])->firstWhere('id', $product['id'])['value'])->toBe('50.000000')
        ->and(collect($analytics['revenue']['by_industry'])->firstWhere('name', 'Technology')['value'])->toBe('300.000000')
        ->and(collect($analytics['revenue']['by_source'])->firstWhere('name', 'website')['value'])->toBe('300.000000');
});

it('executes playbook answers idempotently and preserves versioned definitions', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Grace', 'last_name' => 'Hopper',
    ])->assertCreated()->json('data');
    $automation = Automation::create([
        'tenant_id' => $client['tenant']->id,
        'name' => 'Qualified follow-up', 'event_type' => 'playbook.answer',
        'config' => ['conditions' => [], 'actions' => []], 'active' => true,
    ]);
    $playbook = $api->postJson('/api/v1/playbooks', [
        'name' => 'Discovery', 'entity_type' => 'contact', 'description' => 'Qualification guide',
        'sections' => [[
            'title' => 'Qualification', 'position' => 1, 'required' => true,
            'questions' => [[
                'key' => 'fit', 'prompt' => 'Is this customer qualified?', 'type' => 'select',
                'position' => 1, 'required' => true, 'options' => ['qualified', 'unqualified'],
                'score_config' => ['mapping' => ['qualified' => '10', 'unqualified' => '0']],
                'actions' => [
                    ['type' => 'update_field', 'field' => 'custom_fields.qualification'],
                    ['type' => 'create_task', 'title' => 'Follow up qualified contact', 'assigned_to' => $client['user']->id],
                    ['type' => 'trigger_workflow', 'automation_id' => $automation->id],
                    ['type' => 'calculate_score'],
                ],
            ]],
        ]],
    ])->assertCreated()->assertJsonPath('data.version', 1)->json('data');
    $execution = $api->postJson('/api/v1/playbook-executions', [
        'playbook_id' => $playbook['id'], 'entity_type' => 'contact', 'entity_id' => $contact['id'],
    ])->assertCreated()->assertJsonPath('data.status', 'in_progress')->json('data');
    $questionId = $playbook['sections'][0]['questions'][0]['id'];

    $api->postJson('/api/v1/playbook-executions/'.$execution['id'].'/answers', [
        'question_id' => $questionId, 'value' => 'invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $api->postJson('/api/v1/playbook-executions/'.$execution['id'].'/answers', [
        'question_id' => $questionId, 'value' => 'qualified',
    ])->assertOk()->assertJsonPath('data.score', '10.000000');
    $api->postJson('/api/v1/playbook-executions/'.$execution['id'].'/answers', [
        'question_id' => $questionId, 'value' => 'qualified',
    ])->assertOk()->assertJsonPath('data.score', '10.000000');
    $api->postJson('/api/v1/playbook-executions/'.$execution['id'].'/answers', [
        'question_id' => $questionId, 'value' => 'unqualified',
    ])->assertOk()->assertJsonPath('data.score', '0.000000');
    expect(Contact::query()->findOrFail($contact['id'])->custom_fields['qualification'])->toBe('unqualified');
    $api->postJson('/api/v1/playbook-executions/'.$execution['id'].'/answers', [
        'question_id' => $questionId, 'value' => 'qualified',
    ])->assertOk()->assertJsonPath('data.score', '10.000000');

    expect(Contact::query()->findOrFail($contact['id'])->custom_fields['qualification'])->toBe('qualified')
        ->and(Task::query()->where('related_type', 'contact')->where('related_id', $contact['id'])->count())->toBe(2)
        ->and(PlaybookActionLog::query()->where('status', 'completed')->count())->toBe(8);
    Queue::assertPushed(RunAutomationJob::class, 2);

    $api->postJson('/api/v1/playbook-executions/'.$execution['id'].'/complete')
        ->assertOk()->assertJsonPath('data.status', 'completed');
    $api->patchJson('/api/v1/playbooks/'.$playbook['id'], [
        'sections' => [[
            'title' => 'Changed', 'position' => 1,
            'questions' => [['key' => 'changed', 'prompt' => 'Changed?', 'type' => 'boolean', 'position' => 1]],
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['sections']);
    $api->postJson('/api/v1/playbooks/'.$playbook['id'].'/versions')
        ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.active', false);
});

it('enforces API-5 permissions and tenant isolation', function (): void {
    $foreign = $this->createTenantUser();
    $foreignApi = $this->withToken($foreign['token'])->withHeader('X-Tenant-ID', (string) $foreign['tenant']->id);
    $currency = $foreignApi->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $goal = $foreignApi->postJson('/api/v1/goals', [
        'currency_id' => $currency['id'], 'name' => 'Foreign goal', 'metric' => 'revenue',
        'period_type' => 'monthly', 'starts_at' => today()->startOfMonth()->toDateString(),
        'ends_at' => today()->endOfMonth()->toDateString(),
        'targets' => [['target_type' => 'tenant', 'target_value' => '100']],
    ])->assertCreated()->json('data');

    $viewer = $this->createTenantUser(['forecast.view', 'goals.view']);
    Sanctum::actingAs($viewer['user']);
    $this->flushHeaders();
    $api = $this->withHeader('X-Tenant-ID', (string) $viewer['tenant']->id);
    $api->postJson('/api/v1/forecast/snapshots')->assertForbidden();
    $api->getJson('/api/v1/goals')->assertOk();
    $api->getJson('/api/v1/goals/'.$goal['id'])->assertNotFound();
});
