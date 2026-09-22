<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $portalTitle,
        public readonly string $contactName,
        public readonly string $activationUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activate your '.$this->portalTitle.' account')
            ->greeting('Hello '.$this->contactName)
            ->line('You have been invited to access '.$this->portalTitle.'.')
            ->action('Activate account', $this->activationUrl)
            ->line('This invitation expires in seven days.');
    }
}
