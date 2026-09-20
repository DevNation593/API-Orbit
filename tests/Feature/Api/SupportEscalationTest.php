<?php

namespace Tests\Feature\Api;

use App\Contracts\SupportEscalationNotifier;
use App\Jobs\ProcessSupportSlaJob;
use App\Models\DatabaseNotification;
use App\Models\NotificationPreference;
use App\Models\Permission;
use App\Models\SlaEscalation;
use App\Models\SlaExecution;
use App\Models\SupportAgent;
use App\Models\TenantUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ExistingSupportEscalationNotifier;
use App\Services\NotificationDispatcher;
use App\Services\SlaEngine;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\SupportTestCase;

class SupportEscalationTest extends SupportTestCase
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

    public function test_jobs_record_each_metric_once_and_real_adapter_queues_private_tenant_notifications(): void
    {
        $other = $this->supportFixture();
        $client = $this->bootTicket();
        $client['queue']->update(['escalation_agent_id' => $client['agent']->id]);
        $context = app(TenantContext::class);
        $context->set($other['tenant']->id);
        $this->at('2026-09-14 15:01:00');
        $this->job($client);
        $this->job($client);
        $this->assertSame($other['tenant']->id, $context->id());
        $this->assertSame(1, SlaEscalation::forTenant($client['tenant']->id)->count());
        $row = SlaEscalation::forTenant($client['tenant']->id)->firstOrFail();
        $this->assertSame('dispatched', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertSame([$client['user']->id], array_column($row->recipients, 'user_id'));
        $this->at('2026-09-14 18:01:00');
        $this->job($client);
        $this->job($client);
        $this->assertSame(2, SlaEscalation::forTenant($client['tenant']->id)->count());
        $this->assertSame(2, DatabaseNotification::forTenant($client['tenant']->id)->count());
        $this->assertSame(0, DatabaseNotification::forTenant($other['tenant']->id)->count());
        $notification = DatabaseNotification::forTenant($client['tenant']->id)->firstOrFail();
        $this->assertSame('support.sla_breached', $notification->data['event']);
        $this->assertSame($client['id'], $notification->data['context']['ticket_id']);
        $this->assertStringNotContainsString('PRIVATE TICKET TEXT', $notification->toJson());
        $this->assertDatabaseHas('sla_executions', ['id' => $client['execution_id'], 'first_response_breached' => true, 'resolution_breached' => true]);
        Queue::fake([ProcessSupportSlaJob::class]);
        $this->artisan('support:dispatch-sla')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_dispatch_has_three_durable_attempts_with_sanitized_errors_and_backoff(): void
    {
        $client = $this->bootTicket();
        $this->app->instance(SupportEscalationNotifier::class, new class implements SupportEscalationNotifier
        {
            public function send(User $recipient, SlaEscalation $escalation): bool
            {
                throw new RuntimeException('SECRET_API_KEY simulated provider outage');
            }
        });
        $this->at('2026-09-14 15:01:00');
        $this->job($client);
        $row = SlaEscalation::firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('2026-09-14 15:02:00', $row->next_attempt_at->format('Y-m-d H:i:s'));
        $this->job($client);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->at('2026-09-14 15:02:00');
        $this->job($client);
        $this->assertSame(2, $row->fresh()->attempts);
        $this->assertSame('2026-09-14 15:07:00', $row->fresh()->next_attempt_at->format('Y-m-d H:i:s'));
        $this->at('2026-09-14 15:07:00');
        $this->job($client);
        $this->job($client);
        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame(3, $row->attempts);
        $this->assertSame('failed', $row->recipients[0]['status']);
        $this->assertSame(3, $row->recipients[0]['attempts']);
        $this->assertNull($row->next_attempt_at);
        $this->assertNull($row->reserved_until);
        $this->assertStringNotContainsString('SECRET_API_KEY', json_encode($row->getRawOriginal(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('sla_executions', ['id' => $client['execution_id'], 'first_response_breached' => true]);
    }

    public function test_partial_success_is_not_sent_again_and_delivery_runs_outside_domain_locks(): void
    {
        $client = $this->bootTicket();
        $second = $this->addRecipient($client);
        $adapter = app(ExistingSupportEscalationNotifier::class);
        $this->app->instance(SupportEscalationNotifier::class, new class($adapter, $second->user_id, DB::transactionLevel()) implements SupportEscalationNotifier
        {
            private bool $failed = false;

            public function __construct(private ExistingSupportEscalationNotifier $adapter, private int $secondUser, private int $level) {}

            public function send(User $recipient, SlaEscalation $escalation): bool
            {
                if (DB::transactionLevel() !== $this->level) {
                    throw new RuntimeException('Delivery inside domain transaction');
                }
                if ($recipient->id === $this->secondUser && ! $this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('Transient queue failure');
                }

                return $this->adapter->send($recipient, $escalation);
            }
        });
        $this->at('2026-09-14 15:01:00');
        $this->job($client);
        $row = SlaEscalation::firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame(['dispatched', 'failed'], array_column($row->recipients, 'status'));
        $this->at('2026-09-14 15:02:00');
        $this->job($client);
        $row->refresh();
        $this->assertSame('dispatched', $row->status);
        $this->assertSame([1, 2], array_column($row->recipients, 'attempts'));
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $client['user']->id)->count());
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $second->user_id)->count());
    }

    public function test_disabled_preferences_skip_dispatch_without_claiming_delivery(): void
    {
        $client = $this->bootTicket();
        NotificationPreference::create([
            'tenant_id' => $client['tenant']->id, 'user_id' => $client['user']->id, 'event' => '*',
            'channel' => 'in_app', 'enabled' => false, 'delivery' => 'immediate',
        ]);
        $this->at('2026-09-14 15:01:00');
        $this->job($client);
        $this->assertSame('skipped_preferences', SlaEscalation::firstOrFail()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_captured_recipient_permissions_are_revalidated_before_delivery(): void
    {
        $client = $this->bootTicket();
        $this->recordPending($client);
        $role = $client['user']->memberships()->firstOrFail()->role;
        $role->permissions()->detach(Permission::where('key', 'tickets.reply')->value('id'));
        $this->job($client);
        $row = SlaEscalation::firstOrFail();
        $this->assertSame('skipped_no_recipient', $row->status);
        $this->assertSame('skipped_ineligible', $row->recipients[0]['status']);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_jobs_skip_inactive_tenants_and_foreign_executions_and_restore_context(): void
    {
        $client = $this->bootTicket();
        $other = $this->supportFixture();
        $this->at('2026-09-14 15:01:00');
        app()->call([new ProcessSupportSlaJob($other['tenant']->id, $client['execution_id']), 'handle']);
        $this->assertSame($other['tenant']->id, app(TenantContext::class)->id());
        $client['tenant']->update(['status' => 'inactive']);
        $this->job($client);
        $this->assertSame($other['tenant']->id, app(TenantContext::class)->id());
        $this->assertDatabaseCount('sla_escalations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_expired_lease_recovers_without_overlapping_a_live_attempt(): void
    {
        $client = $this->bootTicket();
        $row = $this->recordPending($client);
        $row->update(['attempts' => 1, 'reserved_until' => '2026-09-14 15:06:00', 'reservation_token' => (string) Str::uuid()]);
        $this->job($client);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertDatabaseCount('notifications', 0);
        $this->at('2026-09-14 15:06:00');
        $this->job($client);
        $row->refresh();
        $this->assertSame('dispatched', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertNull($row->reservation_token);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_expired_third_lease_fails_without_a_fourth_attempt(): void
    {
        $client = $this->bootTicket();
        $row = $this->recordPending($client);
        $row->update(['attempts' => 3, 'reserved_until' => '2026-09-14 15:06:00', 'reservation_token' => (string) Str::uuid()]);
        $this->at('2026-09-14 15:06:00');
        $this->job($client);
        $this->job($client);
        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame(3, $row->attempts);
        $this->assertSame('dispatch_lease_expired', $row->last_error);
        $this->assertNull($row->next_attempt_at);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_scheduler_uses_strict_deadlines_a_global_limit_and_tenant_scopes(): void
    {
        $first = $this->bootTicket();
        $second = $this->bootTicket();
        app(TenantContext::class)->set($second['tenant']->id);
        Queue::fake([ProcessSupportSlaJob::class]);
        $this->at('2026-09-14 15:00:00');
        $this->artisan('support:dispatch-sla')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->at('2026-09-14 15:00:01');
        $this->artisan('support:dispatch-sla', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(ProcessSupportSlaJob::class, fn ($job) => $job->tenantId === $first['tenant']->id && $job->executionId === $first['execution_id'] && $job->queue === 'support');
        Queue::assertPushed(ProcessSupportSlaJob::class, 1);
        $this->assertSame($second['tenant']->id, app(TenantContext::class)->id());
        foreach ([0, 10001, 'bad'] as $limit) {
            $this->artisan('support:dispatch-sla', ['--limit' => $limit])->assertFailed();
        }
    }

    public function test_scheduler_recovers_pending_delivery_for_closed_executions_only_when_ready(): void
    {
        $client = $this->bootTicket();
        $row = $this->recordPending($client);
        SlaExecution::whereKey($client['execution_id'])->update(['status' => 'CLOSED', 'first_response_at' => '2026-09-14 15:01:00']);
        $row->update(['next_attempt_at' => '2026-09-14 15:02:00']);
        Queue::fake([ProcessSupportSlaJob::class]);
        $this->artisan('support:dispatch-sla')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->at('2026-09-14 15:02:00');
        $this->artisan('support:dispatch-sla')->assertSuccessful();
        Queue::assertPushed(ProcessSupportSlaJob::class, fn ($job) => $job->executionId === $client['execution_id']);
        Queue::assertPushed(ProcessSupportSlaJob::class, 1);
    }

    public function test_delayed_job_does_not_breach_a_timely_resolved_ticket(): void
    {
        $client = $this->bootTicket();
        $this->at('2026-09-14 15:00:00');
        $this->postJson('/api/v1/tickets/'.$client['id'].'/comments', ['visibility' => 'PUBLIC', 'body' => 'Done', 'idempotency_key' => 'response'])->assertCreated();
        $this->at('2026-09-14 18:00:00');
        $this->postJson('/api/v1/tickets/'.$client['id'].'/status', ['status' => 'RESOLVED', 'resolution_summary' => 'Done'])->assertOk();
        $this->at('2026-09-15 12:00:00');
        $this->job($client);
        $this->assertDatabaseCount('sla_escalations', 0);
        Queue::fake([ProcessSupportSlaJob::class]);
        $this->artisan('support:dispatch-sla')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_escalation_listing_is_scoped_paginated_and_hides_lease_details(): void
    {
        $client = $this->bootTicket();
        $row = $this->recordPending($client);
        $this->getJson('/api/v1/support/sla-escalations?ticket_id='.$client['id'].'&metric=FIRST_RESPONSE&status=pending&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $row->id)->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.reservation_token')->assertJsonMissingPath('data.0.reserved_until')
            ->assertJsonMissingPath('data.0.last_error')->assertJsonMissingPath('data.0.recipients.0.last_error');
        foreach (['metric=wrong', 'status=wrong', 'per_page=101', 'ticket_id=0'] as $query) {
            $this->getJson('/api/v1/support/sla-escalations?'.$query)->assertUnprocessable();
        }
        $role = $client['user']->memberships()->firstOrFail()->role;
        $role->permissions()->detach(Permission::where('key', 'sla.view')->value('id'));
        $this->getJson('/api/v1/support/sla-escalations')->assertForbidden();
        $other = $this->supportFixture();
        $this->app['auth']->forgetGuards();
        $this->withToken($other['token'])->withHeader('X-Tenant-ID', $other['tenant']->id)
            ->getJson('/api/v1/support/sla-escalations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_stale_reservation_owner_cannot_overwrite_a_new_attempt_or_send_another_recipient(): void
    {
        $client = $this->bootTicket();
        $this->addRecipient($client);
        $row = $this->recordPending($client);
        $replacement = (string) Str::uuid();
        $notifier = new class($replacement) implements SupportEscalationNotifier
        {
            public int $calls = 0;

            public function __construct(private string $replacement) {}

            public function send(User $recipient, SlaEscalation $escalation): bool
            {
                $this->calls++;
                $escalation->update(['reservation_token' => $this->replacement, 'attempts' => 2, 'reserved_until' => now()->addMinutes(5)]);

                return true;
            }
        };
        $this->app->instance(SupportEscalationNotifier::class, $notifier);
        $this->job($client);
        $row->refresh();
        $this->assertSame($replacement, $row->reservation_token);
        $this->assertSame('pending', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertSame(['pending', 'pending'], array_column($row->recipients, 'status'));
        $this->assertSame(1, $notifier->calls);
    }

    public function test_job_restores_context_when_the_domain_raises_an_exception(): void
    {
        $client = $this->bootTicket();
        $other = $this->supportFixture();
        $context = app(TenantContext::class);
        $this->at('2026-09-14 15:01:00');
        // Only the failure trigger is replaced; the job's transaction/context handling stays real.
        $this->mock(SlaEngine::class)->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('Simulated domain failure'));
        try {
            $this->job($client);
            $this->fail('The simulated failure must propagate to the worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated domain failure', $exception->getMessage());
        }
        $this->assertSame($other['tenant']->id, $context->id());
        $this->assertDatabaseCount('sla_escalations', 0);
    }

    public function test_mixed_preferences_and_ineligibility_do_not_claim_dispatch(): void
    {
        $client = $this->bootTicket();
        $second = $this->addRecipient($client);
        $row = $this->recordPending($client);
        NotificationPreference::create([
            'tenant_id' => $client['tenant']->id, 'user_id' => $client['user']->id, 'event' => '*',
            'channel' => 'in_app', 'enabled' => false, 'delivery' => 'immediate',
        ]);
        $second->update(['is_active' => false]);
        $this->job($client);
        $row->refresh();
        $this->assertSame('skipped_no_recipient', $row->status);
        $this->assertSame(['skipped_preferences', 'skipped_ineligible'], array_column($row->recipients, 'status'));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_captured_foreign_user_is_never_notified(): void
    {
        $foreign = $this->supportFixture();
        $client = $this->bootTicket();
        $row = $this->recordPending($client);
        $row->update(['recipients' => [['user_id' => $foreign['user']->id, 'status' => 'pending', 'attempts' => 0, 'last_error' => null]]]);
        $this->job($client);
        $this->assertSame('skipped_no_recipient', $row->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_scheduler_configuration_registers_support_queue_and_minute_dispatch(): void
    {
        $settings = config('horizon.defaults.supervisor-support');
        $this->assertSame('redis', $settings['connection']);
        $this->assertSame(['support'], $settings['queue']);
        $this->assertSame(120, $settings['timeout']);
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event) => str_contains($event->command ?? '', 'support:dispatch-sla'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function bootTicket(): array
    {
        $client = $this->supportFixture();
        $this->app['auth']->forgetGuards();
        $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
        $response = $this->postJson('/api/v1/tickets', [
            'subject' => 'PRIVATE TICKET TEXT', 'description' => 'PRIVATE TICKET TEXT',
            'queue_id' => $client['queue']->id, 'assigned_agent_id' => $client['agent']->id, 'idempotency_key' => 'ticket',
        ])->assertCreated();

        return $client + ['id' => $response->json('data.id'), 'execution_id' => $response->json('data.sla.id')];
    }

    public function test_notification_dispatcher_reports_actual_supported_channel_queueing(): void
    {
        $client = $this->bootTicket();
        app(TenantContext::class)->set($client['tenant']->id);
        $dispatcher = app(NotificationDispatcher::class);
        $this->assertTrue($dispatcher->send($client['user'], 'support.sla_breached', 'Generic', 'Generic'));
        $this->assertDatabaseCount('notifications', 1);
        NotificationPreference::create(['user_id' => $client['user']->id, 'event' => '*', 'channel' => 'in_app', 'enabled' => false, 'delivery' => 'immediate']);
        NotificationPreference::create(['user_id' => $client['user']->id, 'event' => '*', 'channel' => 'push', 'enabled' => true, 'delivery' => 'immediate']);
        $this->assertFalse($dispatcher->send($client['user'], 'support.sla_breached', 'Generic', 'Generic'));
        $this->assertDatabaseCount('notifications', 1);
    }

    private function job(array $client): void
    {
        app()->call([new ProcessSupportSlaJob($client['tenant']->id, $client['execution_id']), 'handle']);
    }

    private function at(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date, 'UTC'));
    }

    private function recordPending(array $client): SlaEscalation
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $this->at('2026-09-14 15:01:00');
        try {
            $context->set($client['tenant']->id);
            DB::transaction(function () use ($client): void {
                $ticket = Ticket::lockForUpdate()->findOrFail($client['id']);
                app(SlaEngine::class)->evaluate($ticket->sla()->lockForUpdate()->firstOrFail(), now()->toImmutable());
            });

            return SlaEscalation::where('execution_id', $client['execution_id'])->firstOrFail();
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function addRecipient(array $client): SupportAgent
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $client['tenant']->id, 'user_id' => $user->id,
            'role_id' => $client['user']->memberships()->firstOrFail()->role_id, 'status' => 'active', 'joined_at' => now(),
        ]);
        $agent = SupportAgent::create(['tenant_id' => $client['tenant']->id, 'user_id' => $user->id]);
        $client['queue']->agents()->attach($agent->id, ['tenant_id' => $client['tenant']->id]);
        $client['queue']->update(['escalation_agent_id' => $agent->id]);

        return $agent;
    }
}
