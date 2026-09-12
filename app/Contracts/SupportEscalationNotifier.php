<?php

namespace App\Contracts;

use App\Models\SlaEscalation;
use App\Models\User;

interface SupportEscalationNotifier
{
    /** True confirms queueing, not provider delivery; false means no immediate supported channel. */
    public function send(User $recipient, SlaEscalation $escalation): bool;
}
