<?php

namespace App\Events;

use App\Models\JourneyExecution;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class JourneyNodeExecuted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly JourneyExecution $execution) {}
}
