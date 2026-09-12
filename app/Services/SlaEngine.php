<?php

namespace App\Services;

use App\Models\SlaBusinessCalendar;
use App\Models\SlaExecution;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SlaEngine
{
    public function __construct(
        private readonly SlaCalendarService $calendars,
        private readonly SlaEscalationService $escalations,
        private readonly AuditService $audit,
    ) {}

    public function recordFirstResponse(Ticket $ticket, CarbonImmutable $at): void
    {
        $execution = $ticket->sla;
        if ($execution === null || $execution->first_response_at !== null) {
            return;
        }
        $execution->first_response_at = $at;
        $this->evaluate($execution, $at);
    }

    /** Caller holds ticket then execution locks. Deadlines use a strict comparison. */
    public function evaluate(SlaExecution $execution, CarbonImmutable $at): void
    {
        $breaches = [];
        $responseAt = $execution->first_response_at ?? $at;
        if (! $execution->first_response_breached && $execution->first_response_due_at !== null
            && $responseAt->gt($execution->first_response_due_at)) {
            $execution->first_response_breached = true;
            $execution->first_response_breached_at = $execution->first_response_due_at;
            $breaches['FIRST_RESPONSE'] = $execution->first_response_due_at;
        }
        if ($execution->status === 'RUNNING' && ! $execution->resolution_breached
            && $execution->resolution_due_at !== null && $at->gt($execution->resolution_due_at)) {
            $execution->resolution_breached = true;
            $execution->resolution_breached_at = $execution->resolution_due_at;
            $breaches['RESOLUTION'] = $execution->resolution_due_at;
        }
        if ($execution->isDirty()) {
            $execution->setUpdatedAt($at)->save();
        }
        foreach ($breaches as $metric => $deadline) {
            $this->escalations->record($execution, $metric, $deadline, $at);
        }
    }

    public function remaining(SlaExecution $execution, CarbonImmutable $at): int
    {
        $budget = (int) $execution->resolution_remaining_seconds;
        if ($execution->status !== 'RUNNING' || $execution->resolution_anchor_at === null || $budget === 0) {
            return $budget;
        }
        // A recorded due date bounds work even when reading very old executions.
        $until = $execution->resolution_due_at !== null && $at->gt($execution->resolution_due_at)
            ? $execution->resolution_due_at : $at;

        return max(0, $budget - $this->calendars->workingSecondsBetween($execution->resolution_anchor_at, $until, $execution->snapshot['calendar']));
    }

    public function transition(Ticket $ticket, string $from, string $to, CarbonImmutable $at): void
    {
        $execution = $ticket->sla;
        if ($execution === null) {
            return;
        }
        $this->evaluate($execution, $at);
        $old = $execution->only(['status', 'resolution_due_at', 'resolution_remaining_seconds', 'paused_at', 'resolved_at']);
        if ($to === 'CLOSED') {
            $execution->status = 'CLOSED';
        } elseif ($to === 'RESOLVED') {
            $execution->resolution_remaining_seconds = $this->remaining($execution, $at);
            $execution->status = 'RESOLVED';
            $execution->resolved_at = $at;
        } elseif ($from === 'RESOLVED' || ($execution->status === 'PAUSED' && $to !== 'WAITING_CUSTOMER')) {
            $execution->resolution_due_at = $this->calendars->addWorkingSeconds($at, $execution->resolution_remaining_seconds, $execution->snapshot['calendar']);
            $execution->last_resolution_due_at = $execution->resolution_due_at;
            $execution->resolution_anchor_at = $at;
            $execution->paused_at = null;
            $execution->resolved_at = null;
            $execution->status = 'RUNNING';
        } elseif ($to === 'WAITING_CUSTOMER' && $execution->snapshot['pause_on_waiting_customer']) {
            $execution->resolution_remaining_seconds = $this->remaining($execution, $at);
            $execution->paused_at = $at;
            $execution->last_resolution_due_at = $execution->resolution_due_at;
            $execution->resolution_due_at = null;
            $execution->status = 'PAUSED';
        }
        if ($execution->isDirty()) {
            $execution->setUpdatedAt($at)->save();
            $this->audit->record('support.sla.transition', $execution, oldValues: $old, newValues: $execution->only(array_keys($old)));
        }
    }

    public function start(Ticket $ticket, CarbonImmutable $at): ?SlaExecution
    {
        $queue = $ticket->queue;
        if ($queue->sla_policy_id === null) {
            return null;
        }
        $policy = SlaPolicy::whereKey($queue->sla_policy_id)->lockForUpdate()->first();
        if ($policy === null || ! $policy->is_active) {
            throw ValidationException::withMessages(['queue_id' => 'The queue must reference an active SLA policy in this tenant.']);
        }
        $rule = $policy->rules()->where('priority', $ticket->priority)->lockForUpdate()->first();
        if ($rule === null || $rule->first_response_minutes < 1
            || $rule->resolution_minutes < $rule->first_response_minutes || $rule->resolution_minutes > 525600) {
            throw ValidationException::withMessages(['priority' => 'The SLA policy needs valid response and resolution targets for this priority.']);
        }
        $calendar = ['mode' => 'ALWAYS', 'timezone' => 'UTC', 'weekly_schedule' => [], 'holidays' => []];
        if ($policy->calendar_id !== null) {
            $record = SlaBusinessCalendar::whereKey($policy->calendar_id)->lockForUpdate()->first();
            if ($record === null || ! $record->is_active) {
                throw ValidationException::withMessages(['queue_id' => 'The SLA policy needs an active calendar in this tenant.']);
            }
            $calendar = [
                'mode' => $record->mode, 'timezone' => $record->timezone,
                'weekly_schedule' => $record->weekly_schedule ?? [], 'holidays' => $record->holidays ?? [],
            ];
        }
        $snapshot = [
            'policy_id' => (int) $policy->id, 'policy_name' => $policy->name, 'priority' => $ticket->priority,
            'first_response_seconds' => $rule->first_response_minutes * 60,
            'resolution_seconds' => $rule->resolution_minutes * 60,
            'pause_on_waiting_customer' => (bool) $policy->pause_on_waiting_customer,
            'calendar' => $this->calendars->normalize($calendar),
        ];
        $firstDue = $this->calendars->addWorkingSeconds($at, $snapshot['first_response_seconds'], $snapshot['calendar']);
        $resolutionDue = $this->calendars->addWorkingSeconds($at, $snapshot['resolution_seconds'], $snapshot['calendar']);

        return SlaExecution::create([
            'ticket_id' => $ticket->id, 'snapshot' => $snapshot, 'status' => 'RUNNING',
            'first_response_due_at' => $firstDue, 'resolution_due_at' => $resolutionDue,
            'last_resolution_due_at' => $resolutionDue, 'resolution_anchor_at' => $at,
            'resolution_remaining_seconds' => $snapshot['resolution_seconds'],
            'first_response_breached' => false, 'resolution_breached' => false,
        ])->fresh();
    }
}
