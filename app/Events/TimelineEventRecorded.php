<?php

namespace App\Events;

use App\Models\Activity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TimelineEventRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Activity $activity) {}

    public function type(): string
    {
        return $this->activity->type;
    }
}
