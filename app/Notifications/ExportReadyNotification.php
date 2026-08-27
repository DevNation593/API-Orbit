<?php

namespace App\Notifications;

use App\Models\ExportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $queue = 'notifications';

    public function __construct(public readonly ExportBatch $batch) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'export.ready', 'batch_id' => $this->batch->id, 'status' => $this->batch->status, 'path' => $this->batch->path];
    }
}
