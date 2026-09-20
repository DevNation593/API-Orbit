<?php

namespace App\Services;

use App\Contracts\SupportEscalationNotifier;
use App\Models\SlaEscalation;
use App\Models\SlaExecution;
use App\Models\SupportAgent;
use App\Models\Tenant;
use App\Support\AuditService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SlaEscalationService
{
    public function __construct(
        private readonly SupportConfigurationService $support,
        private readonly AuditService $audit,
        private readonly SupportEscalationNotifier $notifier,
    ) {}

    public function dispatch(int $escalationId): void
    {
        $tenantId = app(TenantContext::class)->requireId();
        if (! Tenant::whereKey($tenantId)->where('status', 'active')->exists()) {
            return;
        }
        $claimed = DB::transaction(function () use ($escalationId): ?SlaEscalation {
            $row = SlaEscalation::whereKey($escalationId)->lockForUpdate()->firstOrFail();
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($row->status !== 'pending' || $row->next_attempt_at?->gt($at) || $row->reserved_until?->gt($at)) {
                return null;
            }
            if ($row->attempts >= 3) {
                $recipients = $row->recipients;
                foreach ($recipients as &$recipient) {
                    if (in_array($recipient['status'], ['pending', 'failed'], true)) {
                        $recipient['status'] = 'failed';
                        $recipient['last_error'] = 'dispatch_lease_expired';
                    }
                }
                unset($recipient);
                $row->update([
                    'status' => 'failed', 'recipients' => $recipients, 'next_attempt_at' => null,
                    'reserved_until' => null, 'reservation_token' => null, 'last_error' => 'dispatch_lease_expired',
                ]);
                $this->audit->record('support.sla.dispatch', $row, newValues: ['status' => 'failed', 'attempts' => (int) $row->attempts, 'error' => 'dispatch_lease_expired']);

                return null;
            }
            $row->update([
                'attempts' => $row->attempts + 1, 'reserved_until' => $at->addSeconds(300),
                'reservation_token' => (string) Str::uuid(),
            ]);

            return $row->fresh();
        });
        if ($claimed === null) {
            return;
        }
        foreach ($claimed->recipients as $index => $recipient) {
            if (! in_array($recipient['status'], ['pending', 'failed'], true)) {
                continue;
            }
            $current = SlaEscalation::findOrFail($claimed->id);
            if (! $this->ownsLease($current, $claimed->reservation_token)) {
                return;
            }
            $agent = SupportAgent::where('user_id', $recipient['user_id'])->first();
            $eligible = Tenant::whereKey($tenantId)->where('status', 'active')->exists()
                && $agent !== null && $this->support->isEligible($agent);
            $recipient['last_error'] = null;
            if (! $eligible) {
                $recipient['status'] = 'skipped_ineligible';
            } else {
                $recipient['attempts']++;
                try {
                    $recipient['status'] = $this->notifier->send($agent->user, $current) ? 'dispatched' : 'skipped_preferences';
                } catch (Throwable) {
                    // Provider/queue exception messages may contain credentials or message contents.
                    $recipient['status'] = 'failed';
                    $recipient['last_error'] = 'notification_dispatch_failed';
                }
            }
            $recorded = DB::transaction(function () use ($claimed, $index, $recipient): bool {
                $row = SlaEscalation::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
                if (! $this->ownsLease($row, $claimed->reservation_token)) {
                    return false;
                }
                $recipients = $row->recipients;
                $recipients[$index] = $recipient;
                $row->update(['recipients' => $recipients]);

                return true;
            });
            if (! $recorded) {
                return;
            }
        }
        DB::transaction(function () use ($claimed): void {
            $row = SlaEscalation::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            if (! $this->ownsLease($row, $claimed->reservation_token)) {
                return;
            }
            $statuses = array_column($row->recipients, 'status');
            $retryable = array_intersect($statuses, ['pending', 'failed']) !== [];
            $status = match (true) {
                $retryable => $row->attempts < 3 ? 'pending' : 'failed',
                in_array('dispatched', $statuses, true) => 'dispatched',
                $statuses !== [] && array_diff($statuses, ['skipped_preferences']) === [] => 'skipped_preferences',
                default => 'skipped_no_recipient',
            };
            $at = now()->toImmutable()->utc()->startOfSecond();
            $row->update([
                'status' => $status, 'reserved_until' => null, 'reservation_token' => null,
                'next_attempt_at' => $status === 'pending' ? $at->addSeconds($row->attempts === 1 ? 60 : 300) : null,
                'last_error' => $retryable ? 'notification_dispatch_failed' : null,
            ]);
            $this->audit->record('support.sla.dispatch', $row, newValues: [
                'status' => $status, 'attempts' => (int) $row->attempts, 'recipient_count' => count($statuses),
            ]);
        });
    }

    private function ownsLease(SlaEscalation $row, string $token): bool
    {
        return $row->status === 'pending' && $row->reservation_token !== null
            && hash_equals($row->reservation_token, $token)
            && $row->reserved_until?->gt(now()->toImmutable()->utc()->startOfSecond());
    }

    /** Caller holds the ticket and execution locks in one transaction. No delivery here. */
    public function record(SlaExecution $execution, string $metric, CarbonImmutable $deadline, CarbonImmutable $detectedAt): SlaEscalation
    {
        $tenantId = app(TenantContext::class)->requireId();
        abort_unless((int) $execution->tenant_id === $tenantId, 404);
        $key = ['tenant_id' => $tenantId, 'execution_id' => $execution->id, 'metric' => $metric];
        $existing = SlaEscalation::where($key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $ticket = $execution->ticket;
        $queue = $ticket->queue;
        $recipients = [];
        if ($queue !== null && $queue->is_active) {
            $agentIds = array_values(array_unique(array_filter([$ticket->assigned_agent_id, $queue->escalation_agent_id])));
            foreach ($queue->agents()->whereKey($agentIds)->orderBy('support_agents.id')->get() as $agent) {
                if ($this->support->isEligible($agent)) {
                    $recipients[$agent->user_id] = ['user_id' => (int) $agent->user_id, 'status' => 'pending', 'attempts' => 0, 'last_error' => null];
                }
            }
        }
        $escalation = SlaEscalation::firstOrCreate($key, [
            'breached_at' => $deadline, 'detected_at' => $detectedAt,
            'recipients' => array_values($recipients), 'attempts' => 0,
            'status' => $recipients === [] ? 'skipped_no_recipient' : 'pending',
            'next_attempt_at' => $recipients === [] ? null : $detectedAt,
        ]);
        if ($escalation->wasRecentlyCreated) {
            $this->audit->record('support.sla.breached', $escalation, newValues: [
                'ticket_id' => $ticket->id, 'execution_id' => $execution->id, 'metric' => $metric,
                'breached_at' => $deadline->toIso8601String(), 'detected_at' => $detectedAt->toIso8601String(),
            ]);
        }

        return $escalation;
    }
}
