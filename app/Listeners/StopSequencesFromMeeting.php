<?php

namespace App\Listeners;

use App\Events\MeetingBooked;
use App\Services\SequenceStopService;
use App\Support\TenantContext;

class StopSequencesFromMeeting
{
    public function __construct(
        private readonly SequenceStopService $sequences,
        private readonly TenantContext $context,
    ) {}

    public function handle(MeetingBooked $event): void
    {
        $previous = $this->context->id();
        try {
            $this->context->set((int) $event->booking->tenant_id);
            $booking = $event->booking->fresh(['lead', 'contact']);
            if ($booking?->lead !== null) {
                $this->sequences->stopForLead($booking->lead, 'meeting_booked');
            }
            if ($booking?->contact !== null) {
                $this->sequences->stopForContact($booking->contact, 'meeting_booked');
            }
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
