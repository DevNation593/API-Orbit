<?php

namespace App\Services;

use App\Models\SlaEscalation;
use App\Models\SlaExecution;
use App\Support\AuditService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

class SlaEscalationService
{
    public function __construct(
        private readonly SupportConfigurationService $support,
        private readonly AuditService $audit,
    ) {}

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
