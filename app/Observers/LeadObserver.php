<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\DuplicateNormalizer;
use App\Services\TimelineEventRecorder;

class LeadObserver
{
    public function __construct(
        private readonly DuplicateNormalizer $normalizer,
        private readonly TimelineEventRecorder $timeline,
    ) {}

    public function saving(Lead $lead): void
    {
        $this->normalizer->prepareLead($lead);
    }

    public function created(Lead $lead): void
    {
        $this->timeline->record($lead, 'lead.created', ['source' => $lead->source]);
        if ($lead->owner_id !== null) {
            $this->timeline->record($lead, 'lead.assigned', ['owner_id' => (int) $lead->owner_id]);
        }
    }

    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('owner_id') && $lead->owner_id !== null) {
            $this->timeline->record($lead, 'lead.assigned', ['owner_id' => (int) $lead->owner_id]);
        }

        if (($lead->wasChanged('converted_at') && $lead->converted_at !== null)
            || ($lead->wasChanged('status') && $lead->status === 'converted')) {
            $this->timeline->record($lead, 'lead.converted', [
                'contact_id' => $lead->contact_id === null ? null : (int) $lead->contact_id,
                'organization_id' => $lead->organization_id === null ? null : (int) $lead->organization_id,
            ]);
        }
    }
}
