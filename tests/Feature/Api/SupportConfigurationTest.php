<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaBusinessCalendar;
use App\Models\SlaEscalation;
use App\Models\SlaExecution;
use App\Models\SlaPolicy;
use App\Models\SlaRule;
use App\Models\SupportAgent;
use App\Models\SupportQueue;
use App\Models\TenantUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\SupportConfigurationService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\SupportTestCase;

class SupportConfigurationTest extends SupportTestCase
{
    use RefreshDatabase;

    public function test_domain_history_relations_casts_and_database_constraints(): void
    {
        $client = $this->supportFixture();
        $calendar = SlaBusinessCalendar::create([
            'name' => 'Office', 'timezone' => 'America/Guayaquil', 'mode' => 'BUSINESS',
            'weekly_schedule' => ['1' => [['start' => '09:00', 'end' => '17:00']]], 'holidays' => ['2026-12-25'],
        ]);
        $client['policy']->update(['calendar_id' => $calendar->id]);
        $client['queue']->update(['escalation_agent_id' => $client['agent']->id]);
        $this->assertSame($calendar->id, $client['policy']->calendar->id);
        $this->assertSame(['2026-12-25'], $calendar->fresh()->holidays);
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $calendar->fresh()->weekly_schedule[1]);
        $this->assertSame($client['agent']->id, $client['queue']->escalationAgent->id);
        $ticket = $this->ticketFor($client);
        $this->assertSame('OPEN', $ticket->status);
        $this->assertSame('MEDIUM', $ticket->priority);
        $comment = $ticket->comments()->create([
            'author_user_id' => $client['user']->id, 'visibility' => 'PUBLIC',
            'body' => 'Private response body', 'idempotency_key' => 'comment-key', 'payload_hash' => str_repeat('b', 64),
        ]);
        $execution = $ticket->sla()->create([
            'snapshot' => [
                'policy_id' => $client['policy']->id, 'policy_name' => 'Base', 'priority' => 'MEDIUM',
                'first_response_seconds' => 3600, 'resolution_seconds' => 14400,
                'pause_on_waiting_customer' => true,
                'calendar' => ['mode' => 'ALWAYS', 'timezone' => 'UTC', 'weekly_schedule' => [], 'holidays' => []],
            ],
            'resolution_remaining_seconds' => 14400, 'resolution_anchor_at' => '2026-09-09 12:00:00',
            'first_response_due_at' => '2026-09-09 13:00:00', 'resolution_due_at' => '2026-09-09 16:00:00',
            'last_resolution_due_at' => '2026-09-09 16:00:00', 'first_response_breached' => true,
            'first_response_breached_at' => '2026-09-09 13:00:00',
        ]);
        $escalation = $execution->escalations()->create([
            'metric' => 'FIRST_RESPONSE', 'breached_at' => '2026-09-09 13:00:00', 'detected_at' => '2026-09-09 13:01:00',
            'recipients' => [['agent_id' => $client['agent']->id]], 'reservation_token' => '11111111-1111-4111-8111-111111111111',
        ]);
        $execution = $execution->fresh();
        $escalation = $escalation->fresh();
        $this->assertSame($ticket->id, $execution->ticket->id);
        $this->assertSame($execution->id, $ticket->sla->id);
        $this->assertSame($execution->id, $escalation->execution->id);
        $this->assertSame($ticket->id, $comment->ticket->id);
        $this->assertSame($client['user']->id, $comment->author->id);
        $this->assertSame($client['queue']->id, $ticket->queue->id);
        $this->assertSame($client['agent']->id, $ticket->assignee->id);
        $this->assertSame(14400, $execution->resolution_remaining_seconds);
        $this->assertSame(3600, $execution->snapshot['first_response_seconds']);
        $this->assertTrue($execution->first_response_breached);
        $this->assertFalse($execution->resolution_breached);
        $this->assertSame('2026-09-09T13:00:00+00:00', $execution->first_response_breached_at->toIso8601String());
        $this->assertSame('pending', $escalation->status);
        $this->assertSame(0, $escalation->attempts);
        $this->assertSame([['agent_id' => $client['agent']->id]], $escalation->recipients);
        $this->assertArrayNotHasKey('reservation_token', $escalation->toArray());
        $this->assertArrayNotHasKey('payload_hash', $ticket->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $comment->toArray());
        $this->assertConstraintRejected(fn () => $ticket->replicate()->save());
        $this->assertConstraintRejected(fn () => $comment->replicate()->save());
        $this->assertConstraintRejected(fn () => $execution->replicate()->save());
        $this->assertConstraintRejected(fn () => $escalation->replicate()->save());
        $this->assertConstraintRejected(fn () => $client['policy']->rules->first()->replicate()->save());
        $this->assertConstraintRejected(fn () => $client['queue']->agents()->attach($client['agent']->id, ['tenant_id' => $client['tenant']->id]));
        $this->assertConstraintRejected(fn () => $ticket->delete());
        $this->assertConstraintRejected(fn () => $execution->delete());
        $this->assertConstraintRejected(fn () => $client['queue']->delete());
        $this->assertConstraintRejected(fn () => $client['agent']->delete());
        $this->assertConstraintRejected(fn () => $calendar->delete());
        $this->assertConstraintRejected(fn () => $client['policy']->delete());
        $this->assertConstraintRejected(fn () => TicketComment::create([
            'ticket_id' => 999999, 'author_user_id' => $client['user']->id, 'visibility' => 'INTERNAL',
            'body' => 'Invalid', 'idempotency_key' => 'missing-parent', 'payload_hash' => str_repeat('c', 64),
        ]));
        $otherTicket = $this->ticketFor($client, ['idempotency_key' => 'another-ticket']);
        $otherTicket->comments()->create([
            'author_user_id' => $client['user']->id, 'visibility' => 'INTERNAL', 'body' => 'Another note',
            'idempotency_key' => 'comment-key', 'payload_hash' => str_repeat('b', 64),
        ]);
        $foreign = $this->supportFixture();
        $this->ticketFor($foreign);
        foreach ([
            SupportAgent::class, TicketCategory::class, SupportQueue::class, Ticket::class, TicketComment::class,
            SlaBusinessCalendar::class, SlaPolicy::class, SlaRule::class, SlaExecution::class, SlaEscalation::class,
        ] as $class) {
            $this->assertSame(0, $class::where('tenant_id', $client['tenant']->id)->count());
        }
    }

    public function test_audit_is_private_and_failure_rolls_back_configuration_and_membership(): void
    {
        $client = $this->supportFixture(false);
        $service = app(SupportConfigurationService::class);
        $category = $service->saveCategory(['name' => 'Billing', 'description' => 'Private catalog body']);
        $audit = AuditLog::where('entity_type', 'ticket_categories')->where('entity_id', $category->id)->sole();
        $this->assertSame('create', $audit->action);
        $this->assertSame('Billing', $audit->new_values['name'] ?? null);
        $this->assertTrue($audit->new_values['is_active'] ?? false);
        $this->assertStringNotContainsString('Private catalog body', json_encode($audit->new_values));
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulated audit persistence failure');
        });
        foreach ([
            fn () => $service->saveCategory(['name' => 'Should roll back']),
            fn () => $service->replaceQueueAgents($client['queue'], []),
            fn () => $service->delete($category),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected the simulated audit failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Simulated audit persistence failure', $exception->getMessage());
            }
        }
        $this->assertDatabaseMissing('ticket_categories', ['name' => 'Should roll back']);
        $this->assertDatabaseHas('ticket_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('support_queue_agents', ['queue_id' => $client['queue']->id, 'agent_id' => $client['agent']->id]);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_migration_grants_only_system_and_settings_manager_roles(): void
    {
        $manager = $this->createTenantUser(['settings.manage']);
        $custom = $this->createTenantUser(['contacts.view']);
        $system = Role::create(['tenant_id' => $manager['tenant']->id, 'name' => 'System', 'is_system' => true]);
        $migration = require database_path('migrations/2026_09_09_000000_create_support_foundations.php');
        $migration->down();
        $this->assertSame(0, Permission::where('key', 'support.view')->count());
        $migration->up();
        $keys = [
            'support.view', 'support.manage', 'tickets.view', 'tickets.create',
            'tickets.update', 'tickets.assign', 'tickets.reply',
            'tickets.comment_internal', 'tickets.change_status', 'sla.view', 'sla.manage',
        ];
        $this->assertSame(11, $system->permissions()->whereIn('key', $keys)->count());
        $this->assertSame(11, $manager['user']->memberships()->first()->role->permissions()->whereIn('key', $keys)->count());
        $this->assertSame(0, $custom['user']->memberships()->first()->role->permissions()->whereIn('key', $keys)->count());
        $this->assertTrue($custom['user']->hasPermission('contacts.view', $custom['tenant']->id));
    }

    private function ticketFor(array $client, array $attributes = []): Ticket
    {
        return Ticket::create($attributes + [
            'subject' => 'Help', 'queue_id' => $client['queue']->id, 'assigned_agent_id' => $client['agent']->id,
            'created_by' => $client['user']->id, 'idempotency_key' => 'ticket-key', 'payload_hash' => str_repeat('a', 64),
        ])->fresh();
    }

    private function assertConstraintRejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The database accepted a duplicate key or broken history reference.');
        } catch (QueryException $exception) {
            $this->assertContains((string) $exception->getCode(), ['23000', '23503', '23505']);
        }
    }

    public function test_fixture_relations_and_queue_responses_include_the_tenant_members(): void
    {
        $client = $this->supportFixture();
        $this->assertSame('Base', $client['queue']->slaPolicy->name);
        $this->assertNull($client['policy']->calendar);
        $this->assertTrue($client['policy']->pause_on_waiting_customer);
        $rules = $client['policy']->rules->keyBy('priority');
        $this->assertCount(4, $rules);
        foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $priority) {
            $this->assertSame(60, $rules[$priority]->first_response_minutes);
            $this->assertSame(240, $rules[$priority]->resolution_minutes);
            $this->assertSame($client['policy']->id, $rules[$priority]->policy->id);
        }
        $this->assertSame($client['queue']->id, $client['agent']->queues->sole()->id);
        $eagerAgent = SupportAgent::with('queues')->findOrFail($client['agent']->id);
        $this->assertCount(1, $eagerAgent->queues);
        $this->assertSame($client['queue']->id, $eagerAgent->queues->sole()->id);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->getJson('/api/v1/support/queues/'.$client['queue']->id)->assertOk()
            ->assertJsonPath('data.agents.0.id', $client['agent']->id)->assertJsonMissingPath('data.agents.0.user');
        $api->getJson('/api/v1/support/queues')->assertOk()->assertJsonPath('data.0.agents.0.id', $client['agent']->id);
        $withoutSla = $this->supportFixture(false);
        $this->assertNull($withoutSla['policy']);
        $this->assertNull($withoutSla['queue']->slaPolicy);
    }

    public function test_cross_tenant_configuration_references_and_routes_are_hidden(): void
    {
        $foreign = $this->supportFixture();
        $client = $this->supportFixture();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach (['agents' => $foreign['agent']->id, 'queues' => $foreign['queue']->id] as $resource => $id) {
            $api->getJson("/api/v1/support/$resource/$id")->assertNotFound();
            $api->patchJson("/api/v1/support/$resource/$id", ['is_active' => false])->assertNotFound();
            $api->deleteJson("/api/v1/support/$resource/$id")->assertNotFound();
        }
        $api->putJson('/api/v1/support/queues/'.$foreign['queue']->id.'/agents', ['agent_ids' => []])->assertNotFound();
        $api->getJson('/api/v1/support/agents?user_id='.$foreign['user']->id)->assertOk()->assertJsonCount(0, 'data');
        $api->postJson('/api/v1/support/queues', ['name' => 'Invalid', 'sla_policy_id' => $foreign['policy']->id])->assertUnprocessable();
        $api->patchJson('/api/v1/support/queues/'.$client['queue']->id, ['escalation_agent_id' => $foreign['agent']->id])->assertUnprocessable();
        $api->putJson('/api/v1/support/queues/'.$client['queue']->id.'/agents', ['agent_ids' => [$foreign['agent']->id]])->assertUnprocessable();
        $this->assertDatabaseHas('support_queue_agents', ['queue_id' => $client['queue']->id, 'agent_id' => $client['agent']->id]);
    }

    public function test_support_permissions_are_separate_from_sla_permissions(): void
    {
        $client = $this->supportFixture(permissions: ['sla.view', 'sla.manage']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach (['agents', 'categories', 'queues'] as $resource) {
            $api->getJson("/api/v1/support/$resource")->assertForbidden();
            $api->postJson("/api/v1/support/$resource", $resource === 'agents' ? ['user_id' => $client['user']->id] : ['name' => 'Denied'])->assertForbidden();
        }
        $api->patchJson('/api/v1/support/agents/'.$client['agent']->id, ['is_active' => false])->assertForbidden();
        $api->deleteJson('/api/v1/support/agents/'.$client['agent']->id)->assertForbidden();
        $api->putJson('/api/v1/support/queues/'.$client['queue']->id.'/agents', ['agent_ids' => []])->assertForbidden();
        app(TenantContext::class)->set($client['tenant']->id);
        $this->assertTrue(Gate::forUser($client['user'])->allows('view', $client['policy']));
        $this->assertTrue(Gate::forUser($client['user'])->allows('update', $client['policy']));
        $client['user']->memberships()->first()->role->permissions()->sync(Permission::whereIn('key', ['support.view', 'support.manage'])->pluck('id'));
        $this->assertFalse(Gate::forUser($client['user'])->allows('view', $client['policy']));
        $this->assertFalse(Gate::forUser($client['user'])->allows('update', $client['policy']));
        $calendar = SlaBusinessCalendar::create(['name' => 'UTC']);
        $this->assertFalse(Gate::forUser($client['user'])->allows('update', $calendar));
    }

    public function test_inactive_agents_nonmembers_and_inactive_queues_cannot_be_assigned(): void
    {
        $client = $this->supportFixture(false);
        $service = app(SupportConfigurationService::class);
        $client['agent']->update(['is_active' => false]);
        $this->assertValidationError(fn () => $service->assertAssignment($client['queue'], $client['agent']->id), 'assigned_agent_id');
        $client['agent']->update(['is_active' => true]);
        $client['queue']->agents()->detach();
        $this->assertValidationError(fn () => $service->assertAssignment($client['queue'], $client['agent']->id), 'assigned_agent_id');
        $client['queue']->update(['is_active' => false]);
        $this->assertValidationError(fn () => $service->assertAssignment($client['queue'], null), 'queue_id');
        $foreign = $this->supportFixture(false);
        $this->assertValidationError(fn () => $service->assertAssignment($client['queue'], $foreign['agent']->id), 'queue_id');
    }

    public function test_registering_an_agent_checks_the_target_member_not_the_manager(): void
    {
        $client = $this->createTenantUser(['support.manage']);
        app(TenantContext::class)->set($client['tenant']->id);
        $member = User::factory()->create();
        $role = Role::create(['name' => 'Responder']);
        $role->permissions()->sync(Permission::whereIn('key', ['tickets.view', 'tickets.reply'])->pluck('id'));
        $membership = TenantUser::create(['tenant_id' => $client['tenant']->id, 'user_id' => $member->id, 'role_id' => $role->id, 'status' => 'active']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->postJson('/api/v1/support/agents', ['user_id' => $client['user']->id])->assertUnprocessable();
        $api->postJson('/api/v1/support/agents', ['user_id' => $member->id])->assertCreated();
        $membership->update(['status' => 'inactive']);
        $api->postJson('/api/v1/support/queues', ['name' => 'Support'])->assertCreated();
        $api->putJson('/api/v1/support/queues/'.SupportQueue::withoutGlobalScopes()->value('id').'/agents',
            ['agent_ids' => [SupportAgent::withoutGlobalScopes()->value('id')]])->assertUnprocessable();
    }

    private function assertValidationError(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('An invalid reference was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_queue_membership_is_atomic_and_escalation_requires_membership(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $agent = $api->postJson('/api/v1/support/agents', ['user_id' => $client['user']->id])
            ->assertCreated()->json('data.id');
        $api->postJson('/api/v1/support/queues', ['name' => 'Support', 'escalation_agent_id' => $agent])
            ->assertUnprocessable();
        $queue = $api->postJson('/api/v1/support/queues', ['name' => 'Support'])
            ->assertCreated()->json('data.id');
        $api->putJson("/api/v1/support/queues/$queue/agents", ['agent_ids' => [$agent]])->assertOk();
        $this->assertDatabaseHas('support_queue_agents', ['queue_id' => $queue, 'agent_id' => $agent, 'tenant_id' => $client['tenant']->id]);
        $api->putJson("/api/v1/support/queues/$queue/agents", ['agent_ids' => [$agent, 999999]])->assertUnprocessable();
        $api->putJson("/api/v1/support/queues/$queue/agents", ['agent_ids' => [$agent, $agent]])->assertUnprocessable();
        $this->assertDatabaseCount('support_queue_agents', 1);
        $api->patchJson("/api/v1/support/queues/$queue", ['escalation_agent_id' => $agent])->assertOk();
        $api->putJson("/api/v1/support/queues/$queue/agents", ['agent_ids' => []])->assertConflict();
        $api->patchJson("/api/v1/support/agents/$agent", ['is_active' => false])->assertConflict();
        $api->deleteJson("/api/v1/support/agents/$agent")->assertConflict();
        $api->patchJson("/api/v1/support/queues/$queue", ['escalation_agent_id' => null])->assertOk();
        $api->putJson("/api/v1/support/queues/$queue/agents", ['agent_ids' => []])->assertOk();
        $api->deleteJson("/api/v1/support/agents/$agent")->assertOk();
        $api->deleteJson("/api/v1/support/queues/$queue")->assertOk();
    }

    public function test_agent_lookup_validation_and_catalog_crud(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $agent = $api->postJson('/api/v1/support/agents', ['user_id' => $client['user']->id])->assertCreated()->json('data.id');
        $api->getJson('/api/v1/support/agents?user_id='.$client['user']->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 25);
        $api->getJson('/api/v1/support/agents?user_id=999999')->assertOk()->assertJsonCount(0, 'data');
        $api->getJson('/api/v1/support/agents?user_id=0')->assertUnprocessable();
        $api->getJson('/api/v1/support/agents?user_id=1.5')->assertUnprocessable();
        $api->getJson('/api/v1/support/agents?per_page=101')->assertUnprocessable();
        $api->getJson("/api/v1/support/agents/$agent")->assertOk()->assertJsonMissingPath('data.user');
        $api->patchJson("/api/v1/support/agents/$agent", ['user_id' => $client['user']->id])->assertUnprocessable();
        foreach (['categories', 'queues'] as $resource) {
            $api->postJson("/api/v1/support/$resource", ['name' => ''])->assertUnprocessable();
            $api->postJson("/api/v1/support/$resource", ['name' => str_repeat('x', 161)])->assertUnprocessable();
            $api->postJson("/api/v1/support/$resource", ['name' => 'Test', 'description' => str_repeat('x', 5001)])->assertUnprocessable();
            $id = $api->postJson("/api/v1/support/$resource", ['name' => 'Test', 'tenant_id' => 999999])
                ->assertCreated()->assertJsonPath('data.tenant_id', $client['tenant']->id)->json('data.id');
            $api->getJson("/api/v1/support/$resource/$id")->assertOk();
            $api->patchJson("/api/v1/support/$resource/$id", ['name' => 'Updated', 'is_active' => false])
                ->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.is_active', false);
            $api->getJson("/api/v1/support/$resource")->assertOk()->assertJsonCount(1, 'data');
            $api->deleteJson("/api/v1/support/$resource/$id")->assertOk();
            $api->getJson("/api/v1/support/$resource/$id")->assertNotFound();
        }
    }

    public function test_open_tickets_protect_assignment_and_closed_history_prevents_deletion(): void
    {
        $client = $this->createTenantUser();
        app(TenantContext::class)->set($client['tenant']->id);
        $agent = SupportAgent::create(['user_id' => $client['user']->id, 'is_active' => true]);
        $queue = SupportQueue::create(['name' => 'Support']);
        $queue->agents()->attach($agent->id, ['tenant_id' => $client['tenant']->id]);
        $category = TicketCategory::create(['name' => 'Billing']);
        $ticket = Ticket::create([
            'subject' => 'Help', 'queue_id' => $queue->id, 'assigned_agent_id' => $agent->id,
            'category_id' => $category->id, 'created_by' => $client['user']->id,
            'idempotency_key' => 'ticket-1', 'payload_hash' => str_repeat('a', 64),
        ]);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->patchJson("/api/v1/support/agents/$agent->id", ['is_active' => false])->assertConflict();
        $api->patchJson("/api/v1/support/queues/$queue->id", ['is_active' => false])->assertConflict();
        $api->putJson("/api/v1/support/queues/$queue->id/agents", ['agent_ids' => []])->assertConflict();
        $api->deleteJson("/api/v1/support/categories/$category->id")->assertConflict();
        $ticket->update(['status' => 'CLOSED']);
        $api->patchJson("/api/v1/support/agents/$agent->id", ['is_active' => false])->assertOk();
        $api->patchJson("/api/v1/support/queues/$queue->id", ['is_active' => false])->assertOk();
        $api->putJson("/api/v1/support/queues/$queue->id/agents", ['agent_ids' => []])->assertOk();
        $api->deleteJson("/api/v1/support/agents/$agent->id")->assertConflict();
        $api->deleteJson("/api/v1/support/queues/$queue->id")->assertConflict();
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_agent_id' => $agent->id]);
    }

    public function test_assignment_rechecks_permissions_membership_and_queue(): void
    {
        $client = $this->createTenantUser();
        app(TenantContext::class)->set($client['tenant']->id);
        $agent = SupportAgent::create(['user_id' => $client['user']->id, 'is_active' => true]);
        $queue = SupportQueue::create(['name' => 'Support']);
        $queue->agents()->attach($agent->id, ['tenant_id' => $client['tenant']->id]);
        $service = app(SupportConfigurationService::class);
        $this->assertSame($agent->id, $service->assertAssignment($queue, $agent->id)->id);
        $this->assertNull($service->assertAssignment($queue, null));
        $membership = $client['user']->memberships()->first();
        $membership->role->permissions()->detach(Permission::where('key', 'tickets.reply')->value('id'));
        $this->assertFalse($service->isEligible($agent));
        try {
            $service->assertAssignment($queue, $agent->id);
            $this->fail('An agent without reply permission was assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assigned_agent_id', $exception->errors());
        }
        $membership->role->permissions()->attach(Permission::where('key', 'tickets.reply')->value('id'));
        $membership->update(['status' => 'inactive']);
        $this->assertFalse($service->isEligible($agent));
        $this->expectException(ValidationException::class);
        $service->assertAssignment($queue, $agent->id);
    }

    public function test_support_migration_can_be_reversed_and_reapplied_in_memory(): void
    {
        $migration = require database_path('migrations/2026_09_09_000000_create_support_foundations.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('tickets'));
        $this->assertFalse(Schema::hasTable('support_agents'));
        $migration->up();
        foreach (['support_agents', 'ticket_categories', 'sla_business_calendars', 'sla_policies', 'sla_rules', 'support_queues', 'support_queue_agents', 'tickets', 'ticket_comments', 'sla_executions', 'sla_escalations'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['tenant_id', 'created_at', 'updated_at']));
        }
    }

    public function test_registers_own_agent_and_rejects_duplicates(): void
    {
        $client = $this->createTenantUser();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->postJson('/api/v1/support/agents', ['user_id' => $client['user']->id])
            ->assertCreated()->assertJsonPath('data.is_active', true);
        $api->postJson('/api/v1/support/agents', ['user_id' => $client['user']->id])->assertConflict();
    }

    public function test_rejects_external_user_and_member_without_reply_permission(): void
    {
        $client = $this->createTenantUser();
        $external = User::factory()->create();
        $member = User::factory()->create();
        $role = Role::create(['tenant_id' => $client['tenant']->id, 'name' => 'View only']);
        $role->permissions()->sync(Permission::where('key', 'tickets.view')->pluck('id'));
        TenantUser::create([
            'tenant_id' => $client['tenant']->id, 'user_id' => $member->id,
            'role_id' => $role->id, 'status' => 'active',
        ]);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->postJson('/api/v1/support/agents', ['user_id' => $external->id])->assertUnprocessable();
        $api->postJson('/api/v1/support/agents', ['user_id' => $member->id])->assertUnprocessable();
    }

    public function test_hides_foreign_resources_and_requires_manage_permission(): void
    {
        $owner = $this->createTenantUser();
        $other = $this->createTenantUser(['support.view']);
        $id = $this->withToken($owner['token'])->withHeader('X-Tenant-ID', $owner['tenant']->id)
            ->postJson('/api/v1/support/categories', ['name' => 'Private'])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $api = $this->withToken($other['token'])->withHeader('X-Tenant-ID', $other['tenant']->id);
        $api->getJson('/api/v1/support/categories/'.$id)->assertNotFound();
        $api->postJson('/api/v1/support/categories', ['name' => 'Denied'])->assertForbidden();
        $api->getJson('/api/v1/support/categories')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_creates_support_categories_only_in_the_active_tenant(): void
    {
        $client = $this->createTenantUser();
        $response = $this->withToken($client['token'])
            ->withHeader('X-Tenant-ID', $client['tenant']->id)
            ->postJson('/api/v1/support/categories', ['name' => 'Facturación']);
        $response->assertCreated()->assertJsonPath('data.name', 'Facturación');
        $this->assertDatabaseHas('ticket_categories', [
            'id' => $response->json('data.id'), 'tenant_id' => $client['tenant']->id,
        ]);
    }
}
