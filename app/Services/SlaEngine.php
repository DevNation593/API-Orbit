<?php

namespace App\Services;

use App\Models\SlaBusinessCalendar;
use App\Models\SlaExecution;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SlaEngine
{
    public function __construct(private readonly SlaCalendarService $calendars) {}

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
