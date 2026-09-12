<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\SlaBusinessCalendar;
use App\Models\SlaEscalation;
use App\Models\SlaExecution;
use App\Models\Ticket;
use App\Services\SlaEngine;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SupportTestCase;

class TicketLifecycleSlaTest extends SupportTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-09-14 14:00:00');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_public_response_sets_first_response_once_without_sending_messages(): void
    {
        $client = $this->bootTicket();
        $this->postJson($this->url($client, 'comments'), $this->comment('INTERNAL', 'internal'))
            ->assertCreated()->assertJsonMissingPath('data.payload_hash')->assertJsonMissingPath('data.idempotency_key');
        $this->assertDatabaseHas('tickets', ['id' => $client['id'], 'first_response_at' => null]);
        $this->at('2026-09-14 14:15:00');
        $public = $this->comment('PUBLIC', 'public');
        $first = $this->postJson($this->url($client, 'comments'), $public)->assertCreated()->json('data.id');
        $audit = AuditLog::count();
        $this->at('2026-09-14 14:45:00');
        $this->postJson($this->url($client, 'comments'), $public)->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame($audit, AuditLog::count());
        $this->postJson($this->url($client, 'comments'), array_replace($public, ['body' => 'Changed']))->assertConflict();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public-2'))->assertCreated();
        $this->assertDatabaseHas('tickets', ['id' => $client['id'], 'status' => 'OPEN', 'first_response_at' => '2026-09-14 14:15:00']);
        $this->assertDatabaseHas('sla_executions', ['ticket_id' => $client['id'], 'first_response_at' => '2026-09-14 14:15:00', 'first_response_breached' => false]);
        $this->getJson($this->url($client, 'comments').'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->assertDatabaseCount('messages', 0);
        $this->assertStringNotContainsString('Private response body', AuditLog::all()->toJson());
    }

    #[DataProvider('statePairs')]
    public function test_state_transition_matrix(string $from, string $to, bool $allowed): void
    {
        $client = $this->bootTicket(false);
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        Ticket::findOrFail($client['id'])->update(['status' => $from]);
        $before = AuditLog::count();
        $response = $this->postJson($this->url($client, 'status'), ['status' => $to, 'reason' => 'Still failing', 'resolution_summary' => 'Access restored']);
        $response->assertStatus($allowed ? 200 : 409);
        $this->assertDatabaseHas('tickets', ['id' => $client['id'], 'status' => $allowed ? $to : $from]);
        if ($from === $to || ! $allowed) {
            $this->assertSame($before, AuditLog::count());
        }
    }

    public static function statePairs(): array
    {
        $allowed = [
            'OPEN' => ['IN_PROGRESS', 'WAITING_CUSTOMER', 'WAITING_INTERNAL', 'RESOLVED'],
            'IN_PROGRESS' => ['WAITING_CUSTOMER', 'WAITING_INTERNAL', 'RESOLVED'],
            'WAITING_CUSTOMER' => ['IN_PROGRESS', 'WAITING_INTERNAL', 'RESOLVED'],
            'WAITING_INTERNAL' => ['IN_PROGRESS', 'WAITING_CUSTOMER', 'RESOLVED'],
            'RESOLVED' => ['CLOSED', 'IN_PROGRESS'], 'CLOSED' => [],
        ];
        $pairs = [];
        foreach ($allowed as $from => $destinations) {
            foreach (array_keys($allowed) as $to) {
                $pairs[$from.' to '.$to] = [$from, $to, $from === $to || in_array($to, $destinations, true)];
            }
        }

        return $pairs;
    }

    public function test_wait_and_resolution_require_public_response_and_reopening_requires_a_reason(): void
    {
        $client = $this->bootTicket();
        $status = $this->url($client, 'status');
        $this->postJson($status, ['status' => 'WAITING_CUSTOMER'])->assertConflict();
        $this->postJson($status, ['status' => 'RESOLVED', 'resolution_summary' => 'Premature'])->assertConflict();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->postJson($status, ['status' => 'RESOLVED'])->assertUnprocessable();
        $this->postJson($status, ['status' => 'RESOLVED', 'resolution_summary' => 'Fixed'])->assertOk();
        $this->postJson($status, ['status' => 'RESOLVED'])->assertOk();
        $this->postJson($status, ['status' => 'IN_PROGRESS'])->assertUnprocessable();
        $this->postJson($status, ['status' => 'IN_PROGRESS', 'reason' => 'Recurrence'])->assertOk()
            ->assertJsonPath('data.resolved_at', null)->assertJsonPath('data.resolution_summary', null);
        $this->assertStringContainsString('Recurrence', AuditLog::all()->toJson());
        $this->assertStringContainsString('Fixed', AuditLog::all()->toJson());
    }

    public function test_pause_resume_resolve_and_reopen_preserve_the_remaining_budget(): void
    {
        $client = $this->bootTicket();
        $this->at('2026-09-14 14:15:00');
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $status = $this->url($client, 'status');
        $this->at('2026-09-14 15:00:00');
        $this->postJson($status, ['status' => 'WAITING_CUSTOMER'])->assertOk()
            ->assertJsonPath('data.sla.status', 'PAUSED')->assertJsonPath('data.sla.resolution_due_at', null)
            ->assertJsonPath('data.sla.resolution_remaining_seconds', 10800);
        $this->at('2026-09-14 17:00:00');
        $resumed = $this->postJson($status, ['status' => 'IN_PROGRESS'])->assertOk();
        $this->assertSame('2026-09-14 20:00:00', CarbonImmutable::parse($resumed->json('data.sla.resolution_due_at'))->format('Y-m-d H:i:s'));
        $this->at('2026-09-14 18:00:00');
        $this->postJson($status, ['status' => 'RESOLVED', 'resolution_summary' => 'First fix'])->assertOk()
            ->assertJsonPath('data.sla.resolution_remaining_seconds', 7200)->assertJsonPath('data.sla.status', 'RESOLVED');
        $this->at('2026-09-15 10:00:00');
        $reopened = $this->postJson($status, ['status' => 'IN_PROGRESS', 'reason' => 'Recurrence'])->assertOk()
            ->assertJsonPath('data.sla.status', 'RUNNING')->assertJsonPath('data.sla.resolved_at', null)
            ->assertJsonPath('data.sla.resolution_breached', false)->assertJsonPath('data.resolved_at', null);
        $this->assertSame('2026-09-15 12:00:00', CarbonImmutable::parse($reopened->json('data.sla.resolution_due_at'))->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('sla_executions', 1);
    }

    public function test_resolving_a_paused_ticket_keeps_its_pause_history_and_budget(): void
    {
        $client = $this->bootTicket();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->at('2026-09-14 15:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'WAITING_CUSTOMER'])->assertOk();
        $this->at('2026-09-16 17:00:00');
        $resolved = $this->postJson($this->url($client, 'status'), ['status' => 'RESOLVED', 'resolution_summary' => 'Confirmed by customer'])->assertOk()
            ->assertJsonPath('data.sla.resolution_remaining_seconds', 10800)->assertJsonPath('data.sla.resolution_due_at', null)
            ->assertJsonPath('data.sla.resolution_breached', false);
        $this->assertSame('2026-09-14 15:00:00', CarbonImmutable::parse($resolved->json('data.sla.paused_at'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-14 18:00:00', CarbonImmutable::parse($resolved->json('data.sla.last_resolution_due_at'))->format('Y-m-d H:i:s'));
    }

    #[DataProvider('responseDeadlines')]
    public function test_first_response_deadline_is_strict_and_delayed_evaluation_uses_actual_response_time(string $at, bool $breached): void
    {
        $client = $this->bootTicket();
        $this->at($at);
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->getJson($this->url($client, 'sla'))->assertOk()->assertJsonPath('data.first_response_breached', $breached);
        $this->at('2026-09-14 17:00:00');
        $this->evaluate($client);
        $this->evaluate($client);
        $this->getJson($this->url($client, 'sla'))->assertOk()->assertJsonPath('data.first_response_breached', $breached);
        $this->assertDatabaseCount('sla_escalations', $breached ? 1 : 0);
        if ($breached) {
            $this->assertDatabaseHas('sla_escalations', ['metric' => 'FIRST_RESPONSE', 'breached_at' => '2026-09-14 15:00:00']);
        }
    }

    public static function responseDeadlines(): array
    {
        return [['2026-09-14 15:00:00', false], ['2026-09-14 15:00:01', true]];
    }

    public function test_exact_resolution_then_zero_budget_reopen_breaches_only_after_the_reopen_instant(): void
    {
        $client = $this->bootTicket();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->at('2026-09-14 18:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'RESOLVED', 'resolution_summary' => 'Exactly on time'])->assertOk()
            ->assertJsonPath('data.sla.resolution_breached', false)->assertJsonPath('data.sla.resolution_remaining_seconds', 0);
        $this->at('2026-09-15 10:00:00');
        $this->evaluate($client);
        $this->postJson($this->url($client, 'status'), ['status' => 'IN_PROGRESS', 'reason' => 'Repeat'])->assertOk();
        $this->evaluate($client);
        $this->assertDatabaseCount('sla_escalations', 0);
        $this->at('2026-09-15 10:00:01');
        $this->evaluate($client);
        $this->assertDatabaseHas('sla_escalations', ['metric' => 'RESOLUTION', 'breached_at' => '2026-09-15 10:00:00']);
    }

    public function test_late_pause_keeps_the_original_breach_and_captures_deduplicated_recipients(): void
    {
        $client = $this->bootTicket();
        $client['queue']->update(['escalation_agent_id' => $client['agent']->id]);
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->at('2026-09-14 19:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'WAITING_CUSTOMER'])->assertOk()
            ->assertJsonPath('data.sla.resolution_breached', true)->assertJsonPath('data.sla.resolution_remaining_seconds', 0);
        $escalation = SlaEscalation::firstOrFail();
        $this->assertSame('pending', $escalation->status);
        $this->assertSame([$client['user']->id], array_column($escalation->recipients, 'user_id'));
        $this->at('2026-09-14 20:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'IN_PROGRESS'])->assertOk();
        $this->evaluate($client);
        $this->evaluate($client);
        $this->assertDatabaseCount('sla_escalations', 1);
        $this->assertDatabaseHas('sla_executions', ['ticket_id' => $client['id'], 'resolution_breached_at' => '2026-09-14 18:00:00']);
    }

    public function test_internal_wait_keeps_running(): void
    {
        $client = $this->bootTicket();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->at('2026-09-14 15:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'WAITING_INTERNAL'])->assertOk()
            ->assertJsonPath('data.sla.status', 'RUNNING')->assertJsonPath('data.sla.resolution_remaining_seconds', 10800);
        $this->at('2026-09-14 16:00:00');
        $this->getJson($this->url($client, 'sla'))->assertOk()->assertJsonPath('data.resolution_remaining_seconds', 7200);
        $this->at('2026-09-14 19:00:00');
        $this->evaluate($client);
        $this->assertDatabaseHas('sla_executions', ['ticket_id' => $client['id'], 'resolution_breached' => true]);
    }

    public function test_customer_wait_without_pause_uses_the_captured_policy(): void
    {
        $client = $this->bootTicket(pause: false);
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $client['policy']->update(['pause_on_waiting_customer' => true]);
        $this->at('2026-09-14 16:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'WAITING_CUSTOMER'])->assertOk()->assertJsonPath('data.sla.status', 'RUNNING');
        $this->at('2026-09-14 19:00:00');
        $this->evaluate($client);
        $this->assertDatabaseHas('sla_executions', ['ticket_id' => $client['id'], 'resolution_breached' => true]);
    }

    public function test_reads_compute_remaining_time_without_persisting_or_auditing(): void
    {
        $client = $this->bootTicket();
        $stored = SlaExecution::firstOrFail()->getRawOriginal();
        $audit = AuditLog::count();
        $this->at('2026-09-14 16:00:00');
        $this->getJson($this->url($client, 'sla'))->assertOk()->assertJsonPath('data.resolution_remaining_seconds', 7200);
        $this->getJson('/api/v1/tickets/'.$client['id'])->assertOk()->assertJsonPath('data.sla.resolution_remaining_seconds', 7200);
        $this->assertSame($stored, SlaExecution::firstOrFail()->getRawOriginal());
        $this->assertSame($audit, AuditLog::count());
        $this->assertDatabaseCount('sla_escalations', 0);
    }

    public function test_closed_ticket_accepts_only_authorized_comment_replays(): void
    {
        $client = $this->bootTicket(false);
        $public = $this->comment('PUBLIC', 'public');
        $this->postJson($this->url($client, 'comments'), $public)->assertCreated();
        $this->postJson($this->url($client, 'status'), ['status' => 'RESOLVED', 'resolution_summary' => 'Fixed'])->assertOk();
        $internal = $this->comment('INTERNAL', 'internal');
        $this->postJson($this->url($client, 'comments'), $internal)->assertCreated();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'new-public'))->assertConflict();
        $this->postJson($this->url($client, 'status'), ['status' => 'CLOSED'])->assertOk();
        $audit = AuditLog::count();
        $this->postJson($this->url($client, 'comments'), $public)->assertOk();
        $this->postJson($this->url($client, 'comments'), $internal)->assertOk();
        $this->assertSame($audit, AuditLog::count());
        $this->postJson($this->url($client, 'comments'), $this->comment('INTERNAL', 'new-note'))->assertConflict();
        $client['agent']->update(['is_active' => false]);
        $this->postJson($this->url($client, 'comments'), $public)->assertUnprocessable();
        $role = $client['user']->memberships()->firstOrFail()->role;
        $role->permissions()->detach(Permission::where('key', 'tickets.comment_internal')->value('id'));
        $this->postJson($this->url($client, 'comments'), $internal)->assertForbidden();
        $this->assertDatabaseCount('ticket_comments', 2);
        $this->assertDatabaseCount('sla_executions', 0);
    }

    public function test_comment_permissions_eligibility_and_input_bounds(): void
    {
        $client = $this->bootTicket(false);
        $comments = $this->url($client, 'comments');
        foreach ([['body' => ''], ['body' => str_repeat('x', 20001)], ['visibility' => 'CUSTOMER'], ['idempotency_key' => null], ['idempotency_key' => str_repeat('k', 121)], ['author_user_id' => $client['user']->id], ['tenant_id' => null], ['created_at' => '2020-01-01']] as $invalid) {
            $this->postJson($comments, array_replace($this->comment('INTERNAL', 'invalid'), $invalid))->assertUnprocessable();
        }
        $role = $client['user']->memberships()->firstOrFail()->role;
        $role->permissions()->detach(Permission::where('key', 'tickets.comment_internal')->value('id'));
        $this->postJson($comments, $this->comment('INTERNAL', 'denied'))->assertForbidden();
        $client['queue']->agents()->detach($client['agent']->id);
        Ticket::findOrFail($client['id'])->update(['assigned_agent_id' => null]);
        $client['agent']->delete();
        $this->postJson($comments, $this->comment('PUBLIC', 'not-agent'))->assertUnprocessable();
        $this->assertDatabaseCount('ticket_comments', 0);
    }

    public function test_two_missed_metrics_are_recorded_once_without_eligible_recipients(): void
    {
        $client = $this->bootTicket();
        $client['agent']->update(['is_active' => false]);
        $this->at('2026-09-14 19:00:00');
        $this->evaluate($client);
        $audit = AuditLog::count();
        $this->evaluate($client);
        $this->assertSame($audit, AuditLog::count());
        $this->assertDatabaseCount('sla_escalations', 2);
        $this->assertSame(2, SlaEscalation::where('status', 'skipped_no_recipient')->count());
        foreach (SlaEscalation::all() as $escalation) {
            $this->assertSame([], $escalation->recipients);
            $this->assertNull($escalation->next_attempt_at);
        }
    }

    public function test_later_public_response_detects_overdue_resolution_without_overwriting_first_response(): void
    {
        $client = $this->bootTicket();
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'first'))->assertCreated();
        $this->at('2026-09-14 19:00:00');
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'later'))->assertCreated();
        $this->assertDatabaseHas('sla_executions', [
            'ticket_id' => $client['id'], 'first_response_at' => '2026-09-14 14:00:00',
            'first_response_breached' => false, 'resolution_breached' => true,
        ]);
        $this->assertDatabaseCount('sla_escalations', 1);
    }

    public function test_noop_status_does_not_evaluate_clocks_or_add_audits(): void
    {
        $client = $this->bootTicket();
        $stored = SlaExecution::firstOrFail()->getRawOriginal();
        $audit = AuditLog::count();
        $this->at('2026-09-14 19:00:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'OPEN'])->assertOk();
        $this->assertSame($stored, SlaExecution::firstOrFail()->getRawOriginal());
        $this->assertSame($audit, AuditLog::count());
        $this->assertDatabaseCount('sla_escalations', 0);
    }

    public function test_status_permission_and_server_fields_are_enforced(): void
    {
        $client = $this->bootTicket();
        $url = $this->url($client, 'status');
        foreach ([['status' => 'UNKNOWN'], ['resolution_summary' => str_repeat('s', 5001)], ['reason' => str_repeat('r', 2001)], ['resolved_at' => null], ['first_response_breached' => false], ['snapshot' => []]] as $invalid) {
            $this->postJson($url, array_replace(['status' => 'IN_PROGRESS'], $invalid))->assertUnprocessable();
        }
        $role = $client['user']->memberships()->firstOrFail()->role;
        $role->permissions()->detach(Permission::where('key', 'tickets.change_status')->value('id'));
        $this->postJson($url, ['status' => 'IN_PROGRESS'])->assertForbidden();
        $this->getJson('/api/v1/tickets/'.$client['id'])->assertOk()->assertJsonPath('data.status', 'OPEN');
    }

    public function test_business_calendar_resume_skips_the_weekend_and_captured_holiday(): void
    {
        $this->at('2026-09-18 21:00:00');
        $schedule = array_fill_keys(['1', '2', '3', '4', '5'], [['start' => '09:00', 'end' => '17:00']]);
        $client = $this->bootTicket(calendar: [
            'name' => 'Working hours', 'mode' => 'BUSINESS', 'timezone' => 'America/Guayaquil',
            'weekly_schedule' => $schedule, 'holidays' => ['2026-09-21'],
        ]);
        $this->postJson($this->url($client, 'comments'), $this->comment('PUBLIC', 'public'))->assertCreated();
        $this->at('2026-09-18 21:30:00');
        $this->postJson($this->url($client, 'status'), ['status' => 'WAITING_CUSTOMER'])->assertOk()->assertJsonPath('data.sla.resolution_remaining_seconds', 12600);
        SlaBusinessCalendar::firstOrFail()->update(['holidays' => []]);
        $this->at('2026-09-21 14:00:00');
        $response = $this->postJson($this->url($client, 'status'), ['status' => 'IN_PROGRESS'])->assertOk();
        $this->assertSame('2026-09-22 17:30:00', CarbonImmutable::parse($response->json('data.sla.resolution_due_at'))->format('Y-m-d H:i:s'));
        $this->at('2026-09-22 15:00:00');
        $this->getJson($this->url($client, 'sla'))->assertOk()->assertJsonPath('data.resolution_remaining_seconds', 9000);
    }

    private function bootTicket(bool $withSla = true, bool $pause = true, ?array $calendar = null): array
    {
        $client = $this->supportFixture($withSla);
        if ($client['policy'] !== null) {
            $client['policy']->update([
                'pause_on_waiting_customer' => $pause,
                'calendar_id' => $calendar === null ? null : SlaBusinessCalendar::create($calendar)->id,
            ]);
        }
        $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $id = $this->postJson('/api/v1/tickets', [
            'subject' => 'SLA case', 'queue_id' => $client['queue']->id,
            'assigned_agent_id' => $client['agent']->id, 'idempotency_key' => 'ticket',
        ])->assertCreated()->json('data.id');

        return $client + ['id' => $id];
    }

    private function at(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date, 'UTC'));
    }

    private function url(array $client, string $action): string
    {
        return '/api/v1/tickets/'.$client['id'].'/'.$action;
    }

    private function comment(string $visibility, string $key): array
    {
        return ['visibility' => $visibility, 'body' => 'Private response body', 'idempotency_key' => $key];
    }

    private function evaluate(array $client): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        try {
            $context->set($client['tenant']->id);
            DB::transaction(function () use ($client): void {
                $ticket = Ticket::lockForUpdate()->findOrFail($client['id']);
                $execution = $ticket->sla()->lockForUpdate()->firstOrFail();
                app(SlaEngine::class)->evaluate($execution, now()->toImmutable()->utc()->startOfSecond());
            });
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
