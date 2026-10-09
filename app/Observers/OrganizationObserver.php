<?php

namespace App\Observers;

use App\Models\Organization;
use App\Services\DuplicateNormalizer;
use App\Services\TimelineEventRecorder;

class OrganizationObserver
{
    public function __construct(
        private readonly DuplicateNormalizer $normalizer,
        private readonly TimelineEventRecorder $timeline,
    ) {}

    public function saving(Organization $organization): void
    {
        $this->normalizer->prepareOrganization($organization);
    }

    public function created(Organization $organization): void
    {
        $this->timeline->record($organization, 'company.created');
    }

    public function updated(Organization $organization): void
    {
        $tracked = ['name', 'legal_name', 'email', 'phone', 'website', 'owner_id', 'custom_fields'];
        $changed = array_values(array_intersect(array_keys($organization->getChanges()), $tracked));
        if ($changed !== []) {
            $this->timeline->record($organization, 'company.updated', ['changed_fields' => $changed]);
        }
    }
}
