<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class QuoteAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Quote $quote)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function type(): string
    {
        return 'quote.accepted';
    }
}
