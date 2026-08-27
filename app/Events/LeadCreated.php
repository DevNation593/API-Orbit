<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class LeadCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Lead $lead)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function type(): string
    {
        return 'lead.created';
    }
}
