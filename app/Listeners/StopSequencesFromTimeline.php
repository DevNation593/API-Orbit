<?php

namespace App\Listeners;

use App\Events\TimelineEventRecorded;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\SequenceStopService;
use App\Support\TenantContext;

class StopSequencesFromTimeline
{
    public function __construct(
        private readonly SequenceStopService $sequences,
        private readonly TenantContext $context,
    ) {}

    public function handle(TimelineEventRecorded $event): void
    {
        $condition = match ($event->activity->type) {
            'email.received' => 'email_reply',
            'whatsapp.received' => 'whatsapp_reply',
            'opportunity.created' => 'opportunity_created',
            'opportunity.won' => 'deal_won',
            default => null,
        };
        if ($condition === null) {
            return;
        }
        $previous = $this->context->id();
        try {
            $this->context->set((int) $event->activity->tenant_id);
            $subject = $event->activity->activityable;
            if ($subject instanceof Contact) {
                $this->sequences->stopForContact($subject, $condition);
            } elseif ($subject instanceof Lead) {
                $this->sequences->stopForLead($subject, $condition);
            } elseif ($subject instanceof Deal && $subject->contact !== null) {
                $this->sequences->stopForContact($subject->contact, $condition);
            }
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
