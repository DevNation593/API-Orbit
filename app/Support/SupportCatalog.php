<?php

namespace App\Support;

final class SupportCatalog
{
    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public const STATUSES = ['OPEN', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'WAITING_INTERNAL', 'RESOLVED', 'CLOSED'];

    public const VISIBILITIES = ['PUBLIC', 'INTERNAL'];

    public const CALENDAR_MODES = ['ALWAYS', 'BUSINESS'];

    public const SLA_STATUSES = ['RUNNING', 'PAUSED', 'RESOLVED', 'CLOSED'];

    public const METRICS = ['FIRST_RESPONSE', 'RESOLUTION'];

    public const ESCALATION_STATUSES = ['pending', 'dispatched', 'failed', 'skipped_no_recipient', 'skipped_preferences'];
}
