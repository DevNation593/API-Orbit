<?php

namespace App\Events;

use App\Models\Deal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DealStageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Deal $deal, public readonly int $oldStageId, public readonly int $newStageId)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function type(): string
    {
        return 'deal.stage_changed';
    }
}
