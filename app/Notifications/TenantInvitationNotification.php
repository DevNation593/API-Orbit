<?php

namespace App\Notifications;

use App\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly TenantInvitation $invitation,
        public readonly string $acceptanceUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitación a '.$this->invitation->tenant->name)
            ->greeting('Tienes una invitación')
            ->line('Te invitaron a unirte a '.$this->invitation->tenant->name.' con el rol '.$this->invitation->role->name.'.')
            ->action('Aceptar invitación', $this->acceptanceUrl)
            ->line('La invitación vence el '.$this->invitation->expires_at->format('Y-m-d H:i').' UTC.');
    }
}
