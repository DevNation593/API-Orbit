<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrmEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $data
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $event,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $actionUrl = null,
        public readonly string $priority = 'normal',
        public readonly array $channels = ['in_app'],
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        $via = [];
        if (in_array('in_app', $this->channels, true)) {
            $via = ['database', 'broadcast'];
        }
        if (in_array('email', $this->channels, true)) {
            $via[] = 'mail';
        }

        return array_values(array_unique($via));
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'priority' => $this->priority,
            'context' => $this->data,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title)->line($this->body);
        if ($this->actionUrl !== null) {
            $message->action('Abrir en Vantex CRM', $this->actionUrl);
        }

        return $message;
    }
}
