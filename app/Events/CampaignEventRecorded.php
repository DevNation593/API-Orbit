<?php

namespace App\Events;

use App\Models\CampaignEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CampaignEventRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CampaignEvent $event) {}
}
