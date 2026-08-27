<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ContactCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Contact $contact)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function type(): string
    {
        return 'contact.created';
    }
}
