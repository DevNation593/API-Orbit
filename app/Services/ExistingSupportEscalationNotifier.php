<?php

namespace App\Services;

use App\Contracts\SupportEscalationNotifier;
use App\Models\SlaEscalation;
use App\Models\User;

class ExistingSupportEscalationNotifier implements SupportEscalationNotifier
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function send(User $recipient, SlaEscalation $escalation): bool
    {
        return $this->notifications->send(
            $recipient, 'support.sla_breached', 'Incumplimiento de SLA',
            'Un objetivo de atención de soporte requiere revisión.',
            [
                'ticket_id' => (int) $escalation->execution->ticket_id,
                'execution_id' => (int) $escalation->execution_id,
                'metric' => $escalation->metric,
            ],
        );
    }
}
