<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Organization;
use App\Models\SlaBusinessCalendar;
use App\Models\SupportQueue;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SupportTestCase;

class TicketApiTest extends SupportTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-14 14:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_creation_replays_normalized_input_without_duplicate_effects(): void
    {
        $client = $this->supportFixture();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $payload = $this->payload($client);
        $response = $api->postJson('/api/v1/tickets', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.priority', 'MEDIUM')
            ->assertJsonPath('data.assignee_available', false)->assertJsonMissingPath('data.payload_hash')
            ->assertJsonMissingPath('data.idempotency_key');
        $id = $response->json('data.id');
        $this->assertSame('15:00:00', CarbonImmutable::parse($response->json('data.sla.first_response_due_at'))->format('H:i:s'));
        $this->assertSame('18:00:00', CarbonImmutable::parse($response->json('data.sla.resolution_due_at'))->format('H:i:s'));
        $auditCount = AuditLog::count();
        $this->travelTo(CarbonImmutable::parse('2026-09-14 14:30:00', 'UTC'));
        $api->postJson('/api/v1/tickets', $payload + ['description' => null, 'priority' => 'MEDIUM', 'assigned_agent_id' => null])
            ->assertOk()->assertJsonPath('data.id', $id);
        $api->postJson('/api/v1/tickets', array_replace($payload, ['queue_id' => (string) $client['queue']->id]))->assertOk();
        $api->postJson('/api/v1/tickets', array_replace($payload, ['subject' => 'Different payload']))->assertConflict();
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('sla_executions', 1);
        $api->getJson('/api/v1/tickets/'.$id.'/sla')->assertOk()->assertJsonPath('data.snapshot.priority', 'MEDIUM');
    }

    #[DataProvider('priorityDeadlines')]
    public function test_creation_uses_the_rule_for_its_priority(string $priority, int $first, int $resolution, string $firstDue, string $resolutionDue): void
    {
        $client = $this->supportFixture();
        $client['policy']->rules()->where('priority', $priority)->update(['first_response_minutes' => $first, 'resolution_minutes' => $resolution]);
        $response = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id)
            ->postJson('/api/v1/tickets', $this->payload($client) + ['priority' => $priority])->assertCreated()
            ->assertJsonPath('data.sla.snapshot.priority', $priority)->assertJsonPath('data.sla.resolution_remaining_seconds', $resolution * 60);
        $this->assertSame($firstDue, CarbonImmutable::parse($response->json('data.sla.first_response_due_at'))->format('H:i:s'));
        $this->assertSame($resolutionDue, CarbonImmutable::parse($response->json('data.sla.resolution_due_at'))->format('H:i:s'));
    }

    public static function priorityDeadlines(): array
    {
        return [
            ['LOW', 10, 40, '14:10:00', '14:40:00'], ['MEDIUM', 20, 80, '14:20:00', '15:20:00'],
            ['HIGH', 30, 120, '14:30:00', '16:00:00'], ['URGENT', 40, 160, '14:40:00', '16:40:00'],
        ];
    }

    public function test_ticket_without_policy_has_no_execution(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $id = $api->postJson('/api/v1/tickets', $this->payload($client))->assertCreated()->assertJsonPath('data.sla', null)->json('data.id');
        $api->getJson('/api/v1/tickets/'.$id.'/sla')->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('sla_executions', 0);
    }

    public function test_snapshot_survives_priority_assignment_and_configuration_changes(): void
    {
        $client = $this->supportFixture();
        $calendar = SlaBusinessCalendar::create(['name' => 'Original', 'mode' => 'ALWAYS', 'timezone' => 'UTC']);
        $client['policy']->update(['calendar_id' => $calendar->id]);
        $otherQueue = SupportQueue::create(['name' => 'Other queue']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $payload = $this->payload($client) + ['priority' => 'HIGH', 'assigned_agent_id' => $client['agent']->id];
        $response = $api->postJson('/api/v1/tickets', $payload)->assertCreated()->assertJsonPath('data.assignee_available', true);
        $id = $response->json('data.id');
        $snapshot = $response->json('data.sla.snapshot');
        $due = $response->json('data.sla.resolution_due_at');
        $api->patchJson('/api/v1/tickets/'.$id, ['priority' => 'LOW'])->assertOk()->assertJsonPath('data.sla.snapshot.priority', 'HIGH');
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $otherQueue->id, 'assigned_agent_id' => null])
            ->assertOk()->assertJsonPath('data.queue_id', $otherQueue->id)->assertJsonPath('data.assignee_available', false);
        $client['policy']->rules()->update(['resolution_minutes' => 480]);
        $calendar->update(['timezone' => 'America/Guayaquil', 'is_active' => false]);
        $client['policy']->update(['is_active' => false]);
        $client['queue']->update(['is_active' => false]);
        Ticket::findOrFail($id)->update(['status' => 'CLOSED']);
        $auditCount = AuditLog::count();
        $api->postJson('/api/v1/tickets', $payload)->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.sla.snapshot', $snapshot)
            ->assertJsonPath('data.sla.resolution_due_at', $due);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_inactive_policy_calendar_or_missing_rule_rolls_back_ticket_creation(): void
    {
        $client = $this->supportFixture();
        $calendar = SlaBusinessCalendar::create(['name' => 'Inactive', 'is_active' => false]);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $client['policy']->update(['is_active' => false]);
        $api->postJson('/api/v1/tickets', $this->payload($client))->assertUnprocessable();
        $client['policy']->update(['is_active' => true, 'calendar_id' => $calendar->id]);
        $api->postJson('/api/v1/tickets', $this->payload($client))->assertUnprocessable();
        $client['policy']->update(['calendar_id' => null]);
        $client['policy']->rules()->where('priority', 'MEDIUM')->delete();
        $api->postJson('/api/v1/tickets', $this->payload($client))->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('sla_executions', 0);
    }

    public function test_crm_links_are_tenant_scoped_and_contact_matches_conversation(): void
    {
        $foreign = $this->supportFixture();
        $foreignContact = Contact::create(['first_name' => 'Foreign', 'last_name' => 'Contact']);
        $foreignOrganization = Organization::create(['name' => 'Foreign organization']);
        $foreignCategory = TicketCategory::create(['name' => 'Foreign category']);
        $client = $this->supportFixture();
        $contact = Contact::create(['first_name' => 'Private', 'last_name' => 'Contact', 'email' => 'private@example.test']);
        $otherContact = Contact::create(['first_name' => 'Other', 'last_name' => 'Contact']);
        $inbox = Inbox::create(['name' => 'Private inbox', 'created_by' => $client['user']->id]);
        $conversation = Conversation::create(['inbox_id' => $inbox->id, 'channel' => 'web_chat', 'contact_id' => $contact->id, 'subject' => 'Private conversation']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach (['contact_id' => $foreignContact->id, 'organization_id' => $foreignOrganization->id, 'category_id' => $foreignCategory->id, 'assigned_agent_id' => $foreign['agent']->id] as $field => $id) {
            $api->postJson('/api/v1/tickets', $this->payload($client) + [$field => $id])->assertUnprocessable();
        }
        $api->postJson('/api/v1/tickets', $this->payload($client) + ['conversation_id' => $conversation->id, 'contact_id' => $otherContact->id])->assertUnprocessable();
        $response = $api->postJson('/api/v1/tickets', $this->payload($client) + ['conversation_id' => $conversation->id, 'contact_id' => $contact->id])
            ->assertCreated()->assertJsonMissingPath('data.contact')->assertJsonMissingPath('data.conversation');
        $api->patchJson('/api/v1/tickets/'.$response->json('data.id'), ['contact_id' => $otherContact->id])->assertUnprocessable();
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->assertStringNotContainsString('Private conversation', $response->getContent());
    }

    public function test_creation_requires_view_and_explicit_assignment_permission(): void
    {
        $client = $this->supportFixture(false, ['tickets.create']);
        $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id)
            ->postJson('/api/v1/tickets', $this->payload($client))->assertForbidden();
        $client = $this->supportFixture(false, ['tickets.view', 'tickets.create']);
        $this->app['auth']->forgetGuards();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach ([null, $client['agent']->id] as $agentId) {
            $api->postJson('/api/v1/tickets', $this->payload($client) + ['assigned_agent_id' => $agentId])->assertForbidden();
        }
        $id = $api->postJson('/api/v1/tickets', $this->payload($client))->assertCreated()->json('data.id');
        $api->patchJson('/api/v1/tickets/'.$id, ['subject' => 'Denied'])->assertForbidden();
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $client['queue']->id, 'assigned_agent_id' => null])->assertForbidden();
        $client['user']->memberships()->update(['status' => 'inactive']);
        $api->getJson('/api/v1/tickets/'.$id)->assertForbidden();
    }

    public function test_server_fields_and_dedicated_mutation_fields_cannot_be_supplied(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach (['tenant_id' => 999999, 'created_by' => $client['user']->id, 'status' => 'CLOSED', 'first_response_at' => '2026-01-01', 'snapshot' => [], 'sla' => []] as $field => $value) {
            $api->postJson('/api/v1/tickets', $this->payload($client) + [$field => $value])->assertUnprocessable();
        }
        $id = $api->postJson('/api/v1/tickets', $this->payload($client))->assertCreated()->json('data.id');
        foreach (['queue_id' => $client['queue']->id, 'assigned_agent_id' => null, 'status' => 'RESOLVED', 'idempotency_key' => 'new-key'] as $field => $value) {
            $api->patchJson('/api/v1/tickets/'.$id, [$field => $value])->assertUnprocessable();
        }
        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'OPEN', 'first_response_at' => null]);
    }

    public function test_assignment_revalidates_membership_and_respects_terminal_states(): void
    {
        $client = $this->supportFixture(false);
        $otherQueue = SupportQueue::create(['name' => 'Empty queue']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $id = $api->postJson('/api/v1/tickets', $this->payload($client) + ['assigned_agent_id' => $client['agent']->id])->assertCreated()->json('data.id');
        $auditCount = AuditLog::count();
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $client['queue']->id, 'assigned_agent_id' => $client['agent']->id])->assertOk();
        $this->assertSame($auditCount, AuditLog::count());
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $otherQueue->id, 'assigned_agent_id' => $client['agent']->id])->assertUnprocessable();
        $client['agent']->update(['is_active' => false]);
        $api->getJson('/api/v1/tickets/'.$id)->assertOk()->assertJsonPath('data.assignee_available', false);
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $client['queue']->id, 'assigned_agent_id' => $client['agent']->id])->assertUnprocessable();
        Ticket::findOrFail($id)->update(['status' => 'RESOLVED']);
        $api->patchJson('/api/v1/tickets/'.$id, ['subject' => 'Denied'])->assertConflict();
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $otherQueue->id, 'assigned_agent_id' => null])->assertOk();
        Ticket::findOrFail($id)->update(['status' => 'CLOSED']);
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $client['queue']->id, 'assigned_agent_id' => null])->assertConflict();
        $api->patchJson('/api/v1/tickets/'.$id, ['subject' => 'Denied'])->assertConflict();
    }

    public function test_lists_filter_sort_and_hide_foreign_tickets(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $ids = [];
        foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $priority) {
            $ids[$priority] = $api->postJson('/api/v1/tickets', $this->payload($client, $priority) + ['priority' => $priority])->assertCreated()->json('data.id');
        }
        $api->getJson('/api/v1/tickets?sort=priority&direction=asc')->assertOk()->assertJsonPath('data.0.id', $ids['URGENT'])->assertJsonPath('data.3.id', $ids['LOW']);
        $api->getJson('/api/v1/tickets?priority=HIGH&unassigned=1')->assertOk()->assertJsonCount(1, 'data');
        $api->getJson('/api/v1/tickets?q=missing')->assertOk()->assertJsonCount(0, 'data');
        $api->getJson('/api/v1/tickets?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
        foreach (['sort=payload_hash', 'direction=invalid', 'per_page=101', 'unassigned=1&assigned_agent_id=1', 'created_from=invalid'] as $query) {
            $api->getJson('/api/v1/tickets?'.$query)->assertUnprocessable();
        }
        $other = $this->supportFixture(false);
        $this->app['auth']->forgetGuards();
        $api = $this->withToken($other['token'])->withHeader('X-Tenant-ID', $other['tenant']->id);
        $api->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(0, 'data');
        $api->getJson('/api/v1/tickets/'.$ids['LOW'])->assertNotFound();
        $api->getJson('/api/v1/tickets/'.$ids['LOW'].'/sla')->assertNotFound();
        $api->patchJson('/api/v1/tickets/'.$ids['LOW'], ['subject' => 'Hidden'])->assertNotFound();
        $api->postJson('/api/v1/tickets/'.$ids['LOW'].'/assign', ['queue_id' => $other['queue']->id, 'assigned_agent_id' => null])->assertNotFound();
    }

    public function test_creation_revalidates_catalogs_but_authorized_replay_can_use_archived_links(): void
    {
        $client = $this->supportFixture(false);
        $category = TicketCategory::create(['name' => 'Old category', 'is_active' => false]);
        $contact = Contact::create(['first_name' => 'Archived', 'last_name' => 'Contact']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $payload = $this->payload($client) + ['category_id' => $category->id, 'contact_id' => $contact->id];
        $api->postJson('/api/v1/tickets', $payload)->assertUnprocessable();
        $category->update(['is_active' => true]);
        $client['queue']->update(['is_active' => false]);
        $api->postJson('/api/v1/tickets', $payload)->assertUnprocessable();
        $client['queue']->update(['is_active' => true]);
        $id = $api->postJson('/api/v1/tickets', $payload)->assertCreated()->json('data.id');
        $category->update(['is_active' => false]);
        $contact->delete();
        $api->postJson('/api/v1/tickets', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $api->patchJson('/api/v1/tickets/'.$id, ['subject' => 'Keep historical links'])->assertOk();
        $role = $client['user']->memberships()->firstOrFail()->role;
        $permission = $role->permissions()->where('key', 'tickets.create')->firstOrFail();
        $role->permissions()->detach($permission->id);
        $api->postJson('/api/v1/tickets', $payload)->assertForbidden();
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_payload_bounds_and_assignment_contract_reject_invalid_inputs(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach ([
            ['subject' => ''], ['subject' => str_repeat('x', 256)], ['description' => str_repeat('x', 20001)],
            ['idempotency_key' => null], ['idempotency_key' => str_repeat('x', 121)], ['queue_id' => 0],
            ['priority' => null], ['priority' => 'INVALID'], ['contact_id' => -1], ['category_id' => []],
        ] as $invalid) {
            $api->postJson('/api/v1/tickets', array_replace($this->payload($client), $invalid))->assertUnprocessable();
        }
        $id = $api->postJson('/api/v1/tickets', $this->payload($client))->assertCreated()->json('data.id');
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['queue_id' => $client['queue']->id])->assertUnprocessable();
        $api->postJson('/api/v1/tickets/'.$id.'/assign', ['assigned_agent_id' => null])->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_date_boolean_and_bound_query_filters(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->postJson('/api/v1/tickets', $this->payload($client))->assertCreated();
        $api->getJson('/api/v1/tickets?unassigned=true&created_from=2026-09-14T13:00:00Z&created_to=2026-09-14T15:00:00Z')
            ->assertOk()->assertJsonCount(1, 'data');
        $api->getJson('/api/v1/tickets?created_from=2026-09-15')->assertOk()->assertJsonCount(0, 'data');
        $api->getJson('/api/v1/tickets?created_from=2026-09-15&created_to=2026-09-14')->assertUnprocessable();
        $api->getJson('/api/v1/tickets?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_read_requires_view_permission_even_with_other_ticket_permissions(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $id = $api->postJson('/api/v1/tickets', $this->payload($client))->assertCreated()->json('data.id');
        $role = $client['user']->memberships()->firstOrFail()->role;
        $permission = $role->permissions()->where('key', 'tickets.view')->firstOrFail();
        $role->permissions()->detach($permission->id);
        $api->getJson('/api/v1/tickets')->assertForbidden();
        $api->getJson('/api/v1/tickets/'.$id)->assertForbidden();
        $api->getJson('/api/v1/tickets/'.$id.'/sla')->assertForbidden();
        $api->postJson('/api/v1/tickets', $this->payload($client))->assertForbidden();
    }

    public function test_impossible_calendar_deadline_rolls_back_the_entire_creation(): void
    {
        $client = $this->supportFixture();
        $calendar = SlaBusinessCalendar::create([
            'name' => 'One minute weekly', 'mode' => 'BUSINESS', 'timezone' => 'UTC',
            'weekly_schedule' => ['1' => [['start' => '09:00', 'end' => '09:01']]], 'holidays' => [],
        ]);
        $client['policy']->update(['calendar_id' => $calendar->id]);
        $client['policy']->rules()->update(['first_response_minutes' => 525600, 'resolution_minutes' => 525600]);
        $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id)
            ->postJson('/api/v1/tickets', $this->payload($client))->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('sla_executions', 0);
        $this->assertSame(0, AuditLog::where('entity_type', 'tickets')->count());
    }

    private function payload(array $client, string $key = 'ticket-1'): array
    {
        return ['subject' => 'No puedo acceder', 'queue_id' => $client['queue']->id, 'idempotency_key' => $key];
    }
}
