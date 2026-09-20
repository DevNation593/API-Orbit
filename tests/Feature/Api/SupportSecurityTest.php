<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessSupportSlaJob;
use App\Models\AuditLog;
use App\Models\DatabaseNotification;
use App\Models\Permission;
use App\Models\SlaBusinessCalendar;
use App\Models\TicketCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SupportTestCase;

class SupportSecurityTest extends SupportTestCase
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

    public function test_foreign_catalog_details_and_mutations_are_hidden(): void
    {
        $first = $this->supportFixture();
        $category = TicketCategory::create(['name' => 'Secret category']);
        $calendar = SlaBusinessCalendar::create(['name' => 'Secret calendar', 'mode' => 'ALWAYS', 'timezone' => 'UTC']);
        $second = $this->supportFixture();
        $this->authenticate($second);
        foreach ([
            'agents' => $first['agent']->id, 'categories' => $category->id, 'queues' => $first['queue']->id,
            'sla-policies' => $first['policy']->id, 'sla-calendars' => $calendar->id,
        ] as $resource => $id) {
            $url = '/api/v1/support/'.$resource.'/'.$id;
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url, ['is_active' => false])->assertNotFound();
            $this->deleteJson($url)->assertNotFound();
        }
        $this->putJson('/api/v1/support/queues/'.$first['queue']->id.'/agents', ['agent_ids' => [$second['agent']->id]])->assertNotFound();
    }

    public function test_nested_ticket_routes_do_not_reveal_or_mutate_foreign_tickets(): void
    {
        $first = $this->supportFixture();
        $this->authenticate($first);
        $id = $this->createTicket($first);
        $this->postJson('/api/v1/tickets/'.$id.'/comments', ['visibility' => 'INTERNAL', 'body' => 'Private note', 'idempotency_key' => 'note'])->assertCreated();
        $second = $this->supportFixture();
        $this->authenticate($second);
        $url = '/api/v1/tickets/'.$id;
        foreach ([$url, $url.'/comments', $url.'/sla'] as $path) {
            $response = $this->getJson($path)->assertNotFound();
            $this->assertStringNotContainsString('Private note', $response->getContent());
        }
        $this->patchJson($url, ['subject' => 'Attempt'])->assertNotFound();
        $this->postJson($url.'/assign', ['queue_id' => $second['queue']->id, 'assigned_agent_id' => null])->assertNotFound();
        $this->postJson($url.'/status', ['status' => 'IN_PROGRESS'])->assertNotFound();
        $this->postJson($url.'/comments', ['visibility' => 'PUBLIC', 'body' => 'Attempt', 'idempotency_key' => 'note'])->assertNotFound();
        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'OPEN', 'subject' => 'Private ticket']);
        $this->assertDatabaseCount('ticket_comments', 1);
    }

    public function test_idempotency_keys_are_scoped_to_the_tenant_and_ticket(): void
    {
        $first = $this->supportFixture();
        $this->authenticate($first);
        $firstId = $this->createTicket($first);
        $note = ['visibility' => 'INTERNAL', 'body' => 'Private note', 'idempotency_key' => 'same-comment'];
        $firstComment = $this->postJson('/api/v1/tickets/'.$firstId.'/comments', $note)->assertCreated()->json('data.id');
        $second = $this->supportFixture();
        $this->authenticate($second);
        $secondId = $this->createTicket($second);
        $secondComment = $this->postJson('/api/v1/tickets/'.$secondId.'/comments', $note)->assertCreated()->json('data.id');
        $this->assertNotSame($firstId, $secondId);
        $this->assertNotSame($firstComment, $secondComment);
        $this->postJson('/api/v1/tickets/'.$secondId.'/comments', $note)->assertOk()->assertJsonPath('data.id', $secondComment);
        $this->getJson('/api/v1/tickets?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $secondId);
    }

    public function test_management_and_reply_permissions_do_not_grant_other_ticket_actions(): void
    {
        $client = $this->supportFixture();
        $this->authenticate($client);
        $id = $this->createTicket($client);
        $role = $client['user']->memberships()->firstOrFail()->role;
        foreach ([
            ['support.manage', 'assign', ['queue_id' => $client['queue']->id, 'assigned_agent_id' => null]],
            ['sla.manage', 'status', ['status' => 'IN_PROGRESS']],
            ['tickets.reply', 'comments', ['visibility' => 'INTERNAL', 'body' => 'No access', 'idempotency_key' => 'denied']],
        ] as [$permission, $action, $payload]) {
            $role->permissions()->sync(Permission::whereIn('key', ['tickets.view', $permission])->pluck('id'));
            $this->postJson('/api/v1/tickets/'.$id.'/'.$action, $payload)->assertForbidden();
        }
        $role->permissions()->sync(Permission::where('key', 'tickets.create')->pluck('id'));
        $this->postJson('/api/v1/tickets', ['subject' => 'No view', 'queue_id' => $client['queue']->id, 'idempotency_key' => 'denied'])->assertForbidden();
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_comments', 0);
    }

    public function test_foreign_payload_references_fail_on_the_reference_field(): void
    {
        $first = $this->supportFixture();
        $calendar = SlaBusinessCalendar::create(['name' => 'Foreign', 'mode' => 'ALWAYS', 'timezone' => 'UTC']);
        $second = $this->supportFixture();
        $this->authenticate($second);
        $this->postJson('/api/v1/support/queues', ['name' => 'Foreign policy', 'sla_policy_id' => $first['policy']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('sla_policy_id');
        $rules = array_map(fn ($priority) => ['priority' => $priority, 'first_response_minutes' => 60, 'resolution_minutes' => 240], ['LOW', 'MEDIUM', 'HIGH', 'URGENT']);
        $this->postJson('/api/v1/support/sla-policies', ['name' => 'Foreign calendar', 'calendar_id' => $calendar->id, 'rules' => $rules])
            ->assertUnprocessable()->assertJsonValidationErrors('calendar_id');
        $this->putJson('/api/v1/support/queues/'.$second['queue']->id.'/agents', ['agent_ids' => [$first['agent']->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_ids');
        $this->postJson('/api/v1/tickets', ['subject' => 'Foreign queue', 'queue_id' => $first['queue']->id, 'idempotency_key' => 'foreign'])
            ->assertUnprocessable()->assertJsonValidationErrors('queue_id');
    }

    public function test_server_fields_cannot_be_forged_and_history_cannot_be_deleted(): void
    {
        $client = $this->supportFixture();
        $this->authenticate($client);
        $payload = ['subject' => 'Case', 'queue_id' => $client['queue']->id, 'idempotency_key' => 'safe'];
        foreach (['tenant_id' => 999, 'created_by' => 999, 'created_at' => '2020-01-01', 'status' => 'CLOSED', 'snapshot' => [], 'first_response_at' => '2020-01-01', 'resolution_breached' => true] as $field => $value) {
            $this->postJson('/api/v1/tickets', $payload + [$field => $value])->assertUnprocessable();
        }
        $id = $this->postJson('/api/v1/tickets', $payload)->assertCreated()->json('data.id');
        $note = ['visibility' => 'INTERNAL', 'body' => 'Safe', 'idempotency_key' => 'safe'];
        foreach (['tenant_id' => null, 'ticket_id' => 999, 'author_user_id' => 999, 'created_at' => '2020-01-01', 'status' => 'CLOSED', 'snapshot' => []] as $field => $value) {
            $this->postJson('/api/v1/tickets/'.$id.'/comments', $note + [$field => $value])->assertUnprocessable();
        }
        $this->deleteJson('/api/v1/tickets/'.$id)->assertStatus(405);
        $this->getJson('/api/v1/tickets/not-numeric')->assertNotFound();
        $this->assertDatabaseHas('tickets', ['id' => $id, 'tenant_id' => $client['tenant']->id, 'status' => 'OPEN', 'first_response_at' => null]);
        $this->assertDatabaseCount('ticket_comments', 0);
    }

    public function test_private_comments_do_not_leak_into_audits_or_alerts(): void
    {
        $client = $this->supportFixture();
        $this->authenticate($client);
        $id = $this->createTicket($client);
        $this->travelTo(CarbonImmutable::parse('2026-09-14 15:01:00', 'UTC'));
        $this->postJson('/api/v1/tickets/'.$id.'/comments', ['visibility' => 'INTERNAL', 'body' => 'PRIVATE_COMMENT_SECRET', 'idempotency_key' => 'private'])->assertCreated();
        $executionId = $this->getJson('/api/v1/tickets/'.$id.'/sla')->assertOk()->json('data.id');
        app()->call([new ProcessSupportSlaJob($client['tenant']->id, $executionId), 'handle']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertStringNotContainsString('PRIVATE_COMMENT_SECRET', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('PRIVATE_COMMENT_SECRET', DatabaseNotification::all()->toJson());
        $this->assertDatabaseCount('messages', 0);
    }

    private function authenticate(array $client): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    }

    private function createTicket(array $client): int
    {
        return $this->postJson('/api/v1/tickets', [
            'subject' => 'Private ticket', 'queue_id' => $client['queue']->id,
            'assigned_agent_id' => $client['agent']->id, 'idempotency_key' => 'same-ticket',
        ])->assertCreated()->json('data.id');
    }
}
