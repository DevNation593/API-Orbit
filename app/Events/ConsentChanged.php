<?php

namespace App\Events;

use App\Models\ConsentRecord;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ConsentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly ConsentRecord $consent) {}
}
