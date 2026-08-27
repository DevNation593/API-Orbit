<?php

namespace App\Listeners;

use App\Events\ContactCreated;
use App\Events\DealStageChanged;
use App\Events\LeadCreated;
use App\Events\TaskCompleted;
use App\Jobs\RunAutomationJob;
use App\Models\Automation;
use App\Support\TenantContext;

class DispatchAutomation
{
    public function handle(ContactCreated|LeadCreated|DealStageChanged|TaskCompleted $event): void
    {
        $model = match (true) {
            $event instanceof ContactCreated => $event->contact,
            $event instanceof LeadCreated => $event->lead,
            $event instanceof TaskCompleted => $event->task,
            default => $event->deal,
        };

        $tenantId = (int) $model->tenant_id;
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($tenantId);

        try {
            Automation::query()
                ->where('event_type', $event->type())
                ->where('active', true)
                ->pluck('id')
                ->each(fn (int $automationId) => RunAutomationJob::dispatch(
                    $tenantId,
                    $automationId,
                    $event->type(),
                    $event->eventId,
                    $model::class,
                    (int) $model->getKey(),
                )->onQueue('automations'));
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
