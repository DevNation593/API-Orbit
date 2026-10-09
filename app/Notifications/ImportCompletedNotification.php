<?php

namespace App\Notifications;

use App\Models\ImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ImportBatch $batch)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->batch->tenant_id,
            'event' => 'import.completed',
            'type' => 'import.completed',
            'title' => 'Importación completada',
            'body' => 'El proceso de importación terminó con estado '.$this->batch->status.'.',
            'batch_id' => $this->batch->id,
            'status' => $this->batch->status,
            'summary' => $this->batch->summary,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
