<?php

namespace App\Observers;

use App\Models\Task;
use App\Services\TimelineEventRecorder;

class TaskObserver
{
    public function __construct(private readonly TimelineEventRecorder $timeline) {}

    public function created(Task $task): void
    {
        $this->timeline->record($task, 'task.created', [
            'assigned_to' => $task->assigned_to === null ? null : (int) $task->assigned_to,
            'related_type' => $task->related_type,
            'related_id' => $task->related_id,
        ]);
        if ($task->status === 'completed') {
            $this->timeline->record($task, 'task.completed');
        }
    }

    public function updated(Task $task): void
    {
        if ($task->wasChanged('status') && $task->status === 'completed') {
            $this->timeline->record($task, 'task.completed');
        }
    }
}
