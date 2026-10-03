<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadCaptureEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Lead $lead, public readonly LeadCaptureEvent $capture)
    {
        $this->eventId = 'capture-'.$capture->id;
    }

    public function type(): string
    {
        return 'lead.captured';
    }
}
