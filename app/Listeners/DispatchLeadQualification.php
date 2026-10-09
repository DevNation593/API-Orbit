<?php

namespace App\Listeners;

use App\Events\LeadCaptured;
use App\Jobs\ProcessLeadRoutingJob;
use App\Jobs\RecalculateLeadScoreJob;

class DispatchLeadQualification
{
    public function handle(LeadCaptured $event): void
    {
        $tenantId = (int) $event->lead->tenant_id;
        ProcessLeadRoutingJob::dispatch($tenantId, (int) $event->lead->id, $event->eventId)
            ->onQueue('automations');
        RecalculateLeadScoreJob::dispatch(
            $tenantId,
            (int) $event->lead->id,
            $event->type(),
            $event->eventId,
            ['origin' => $event->capture->origin, 'attribution' => $event->capture->attribution ?? []],
        )->onQueue('automations');
    }
}
