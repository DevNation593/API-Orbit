<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\SlaBusinessCalendar;
use App\Models\SlaExecution;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SupportTestCase;

class SlaConfigurationTest extends SupportTestCase
{
    use RefreshDatabase;

    public function test_calendar_and_policy_crud_preserve_complete_rules(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $calendarId = $api->postJson('/api/v1/support/sla-calendars', $this->calendar())
            ->assertCreated()->assertJsonPath('data.timezone', 'America/Guayaquil')->json('data.id');
        $policyId = $api->postJson('/api/v1/support/sla-policies', [
            'name' => 'Office SLA', 'calendar_id' => $calendarId, 'rules' => $this->rules(),
        ])->assertCreated()->assertJsonCount(4, 'data.rules')->assertJsonPath('data.pause_on_waiting_customer', true)->json('data.id');
        foreach (['sla-calendars' => $calendarId, 'sla-policies' => $policyId] as $resource => $id) {
            $api->getJson('/api/v1/support/'.$resource)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 25);
            $api->getJson('/api/v1/support/'.$resource.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
            $api->getJson('/api/v1/support/'.$resource.'?per_page=101')->assertUnprocessable();
        }
        $api->patchJson('/api/v1/support/sla-calendars/'.$calendarId, ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.weekly_schedule.1.0.start', '09:00');
        $ids = SlaPolicy::findOrFail($policyId)->rules()->pluck('id')->all();
        $api->patchJson('/api/v1/support/sla-policies/'.$policyId, ['name' => 'Renamed SLA'])->assertOk()->assertJsonCount(4, 'data.rules');
        $this->assertSame($ids, SlaPolicy::findOrFail($policyId)->rules()->pluck('id')->all());
        $api->patchJson('/api/v1/support/sla-policies/'.$policyId, ['rules' => $this->rules(30, 120), 'pause_on_waiting_customer' => false])
            ->assertOk()->assertJsonCount(4, 'data.rules')->assertJsonPath('data.rules.0.first_response_minutes', 30)
            ->assertJsonPath('data.pause_on_waiting_customer', false);
        $this->assertDatabaseCount('sla_rules', 4);
        $api->deleteJson('/api/v1/support/sla-calendars/'.$calendarId)->assertConflict();
        $api->deleteJson('/api/v1/support/sla-policies/'.$policyId)->assertOk();
        $this->assertDatabaseCount('sla_rules', 0);
        $api->deleteJson('/api/v1/support/sla-calendars/'.$calendarId)->assertOk();
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_rules_do_not_create_partial_policies(array $rules): void
    {
        $client = $this->supportFixture(false);
        $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id)
            ->postJson('/api/v1/support/sla-policies', ['name' => 'Invalid', 'rules' => $rules])->assertUnprocessable();
        $this->assertDatabaseCount('sla_policies', 0);
        $this->assertDatabaseCount('sla_rules', 0);
    }

    public static function invalidRules(): array
    {
        $rules = array_map(fn ($priority) => ['priority' => $priority, 'first_response_minutes' => 60, 'resolution_minutes' => 240], ['LOW', 'MEDIUM', 'HIGH', 'URGENT']);
        $cases = ['incomplete' => [array_slice($rules, 0, 3)], 'empty' => [[]]];
        foreach ([
            'duplicate priority' => ['priority', 'MEDIUM'],
            'unknown priority' => ['priority', 'CRITICAL'],
            'zero' => ['first_response_minutes', 0],
            'negative' => ['resolution_minutes', -1],
            'fractional' => ['first_response_minutes', 1.5],
            'too large' => ['resolution_minutes', 525601],
            'response after resolution' => ['first_response_minutes', 241],
        ] as $name => [$field, $value]) {
            $invalid = $rules;
            $invalid[0][$field] = $value;
            $cases[$name] = [$invalid];
        }

        return $cases;
    }

    public function test_failed_rule_replacement_and_referenced_deletion_are_atomic(): void
    {
        $client = $this->supportFixture();
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $policyId = $client['policy']->id;
        $api->patchJson('/api/v1/support/sla-policies/'.$policyId, ['name' => 'Must roll back', 'rules' => $this->rules(300, 240)])->assertUnprocessable();
        $this->assertSame('Base', $client['policy']->fresh()->name);
        $this->assertDatabaseHas('sla_rules', ['policy_id' => $policyId, 'priority' => 'HIGH', 'first_response_minutes' => 60]);
        $api->deleteJson('/api/v1/support/sla-policies/'.$policyId)->assertConflict();
        $this->assertDatabaseCount('sla_rules', 4);
        $api->patchJson('/api/v1/support/sla-policies/'.$policyId, ['is_active' => false])->assertOk();
    }

    public function test_cross_tenant_or_inactive_calendars_are_not_accepted(): void
    {
        $foreign = $this->supportFixture();
        $foreignCalendar = SlaBusinessCalendar::create(['name' => 'Foreign']);
        $client = $this->supportFixture();
        $inactive = SlaBusinessCalendar::create(['name' => 'Inactive', 'is_active' => false]);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        foreach (['sla-calendars' => $foreignCalendar->id, 'sla-policies' => $foreign['policy']->id] as $resource => $id) {
            $api->getJson('/api/v1/support/'.$resource.'/'.$id)->assertNotFound();
            $api->patchJson('/api/v1/support/'.$resource.'/'.$id, ['name' => 'Hidden'])->assertNotFound();
            $api->deleteJson('/api/v1/support/'.$resource.'/'.$id)->assertNotFound();
        }
        foreach ([$foreignCalendar->id, $inactive->id, 999999] as $id) {
            $api->postJson('/api/v1/support/sla-policies', ['name' => 'Invalid', 'calendar_id' => $id, 'rules' => $this->rules()])->assertUnprocessable();
            $api->patchJson('/api/v1/support/sla-policies/'.$client['policy']->id, ['calendar_id' => $id])->assertUnprocessable();
        }
        $this->assertNull($client['policy']->fresh()->calendar_id);
    }

    public function test_sla_permissions_are_independent_from_support_management(): void
    {
        $client = $this->supportFixture(permissions: ['support.view', 'support.manage']);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->getJson('/api/v1/support/sla-calendars')->assertForbidden();
        $api->getJson('/api/v1/support/sla-policies')->assertForbidden();
        $api->postJson('/api/v1/support/sla-calendars', $this->calendar())->assertForbidden();
        $api->postJson('/api/v1/support/sla-policies', ['name' => 'Denied', 'rules' => $this->rules()])->assertForbidden();
        $api->patchJson('/api/v1/support/sla-policies/'.$client['policy']->id, ['name' => 'Denied'])->assertForbidden();
        $api->deleteJson('/api/v1/support/sla-policies/'.$client['policy']->id)->assertForbidden();
        $client['user']->memberships()->first()->role->permissions()->sync(Permission::where('key', 'sla.view')->pluck('id'));
        $this->app['auth']->forgetGuards();
        $api->getJson('/api/v1/support/sla-policies')->assertOk();
        $api->getJson('/api/v1/support/sla-policies/'.$client['policy']->id)->assertOk();
        $api->patchJson('/api/v1/support/sla-policies/'.$client['policy']->id, ['name' => 'Denied'])->assertForbidden();
    }

    public function test_calendar_validation_prevents_partial_updates(): void
    {
        $client = $this->supportFixture(false);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $id = $api->postJson('/api/v1/support/sla-calendars', $this->calendar())->assertCreated()->json('data.id');
        $api->patchJson('/api/v1/support/sla-calendars/'.$id, ['name' => 'Must not change', 'weekly_schedule' => []])->assertUnprocessable();
        $api->patchJson('/api/v1/support/sla-calendars/'.$id, ['timezone' => '+02:00'])->assertUnprocessable();
        $this->assertSame('Office', SlaBusinessCalendar::findOrFail($id)->name);
        $api->postJson('/api/v1/support/sla-calendars', ['name' => 'Continuous', 'mode' => 'ALWAYS', 'timezone' => 'UTC'])
            ->assertCreated()->assertJsonPath('data.weekly_schedule', [])->assertJsonPath('data.holidays', []);
    }

    public function test_historical_policy_remains_protected_after_queue_changes(): void
    {
        $client = $this->supportFixture();
        $ticket = Ticket::create(['subject' => 'History', 'queue_id' => $client['queue']->id, 'created_by' => $client['user']->id, 'idempotency_key' => 'history', 'payload_hash' => str_repeat('a', 64)]);
        $snapshot = ['policy_id' => $client['policy']->id, 'priority' => 'MEDIUM', 'resolution_seconds' => 14400];
        $execution = SlaExecution::create(['ticket_id' => $ticket->id, 'snapshot' => $snapshot]);
        $client['queue']->update(['sla_policy_id' => null]);
        $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $api->patchJson('/api/v1/support/sla-policies/'.$client['policy']->id, ['rules' => $this->rules(30, 120), 'is_active' => false])->assertOk();
        $this->assertEquals($snapshot, $execution->fresh()->snapshot);
        $api->deleteJson('/api/v1/support/sla-policies/'.$client['policy']->id)->assertConflict();
        $this->assertDatabaseCount('sla_rules', 4);
    }

    private function calendar(): array
    {
        return ['name' => 'Office', 'mode' => 'BUSINESS', 'timezone' => 'America/Guayaquil', 'weekly_schedule' => array_fill_keys([1, 2, 3, 4, 5], [['start' => '09:00', 'end' => '17:00']]), 'holidays' => ['2026-12-25']];
    }

    private function rules(int $first = 60, int $resolution = 240): array
    {
        return array_map(fn ($priority) => ['priority' => $priority, 'first_response_minutes' => $first, 'resolution_minutes' => $resolution], ['LOW', 'MEDIUM', 'HIGH', 'URGENT']);
    }
}
