<?php

namespace App\Notifications;

use App\Models\ExportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ExportBatch $batch)
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
            'event' => 'export.ready',
            'type' => 'export.ready',
            'title' => 'Exportación lista',
            'body' => 'El archivo de exportación ya está disponible.',
            'batch_id' => $this->batch->id,
            'status' => $this->batch->status,
            'path' => $this->batch->path,
        ];
    }
}
