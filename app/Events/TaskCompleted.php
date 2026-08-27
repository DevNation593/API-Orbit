<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class TaskCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Task $task)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function type(): string
    {
        return 'task.completed';
    }
}
