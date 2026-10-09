<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\DuplicateNormalizer;
use App\Services\TimelineEventRecorder;

class ContactObserver
{
    public function __construct(
        private readonly DuplicateNormalizer $normalizer,
        private readonly TimelineEventRecorder $timeline,
    ) {}

    public function saving(Contact $contact): void
    {
        $this->normalizer->prepareContact($contact);
    }

    public function created(Contact $contact): void
    {
        $this->timeline->record($contact, 'contact.created');
    }

    public function updated(Contact $contact): void
    {
        $tracked = ['first_name', 'last_name', 'email', 'phone', 'owner_id', 'status', 'custom_fields'];
        $changed = array_values(array_intersect(array_keys($contact->getChanges()), $tracked));
        if ($changed !== []) {
            $this->timeline->record($contact, 'contact.updated', ['changed_fields' => $changed]);
        }
    }
}
