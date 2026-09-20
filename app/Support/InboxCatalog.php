<?php

namespace App\Support;

final class InboxCatalog
{
    public const CHANNELS = [
        'email', 'whatsapp', 'sms', 'web_chat', 'facebook', 'instagram', 'telegram', 'call',
    ];

    public const CONVERSATION_STATUSES = [
        'open', 'pending', 'waiting_customer', 'resolved', 'closed',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const MESSAGE_TYPES = [
        'text', 'image', 'video', 'document', 'audio', 'template', 'email', 'system',
    ];

    public const MESSAGE_STATUSES = [
        'scheduled', 'queued', 'sent', 'delivered', 'read', 'received', 'failed',
    ];

    public const NOTIFICATION_CHANNELS = ['in_app', 'email', 'push', 'whatsapp', 'sms'];

    public const NOTIFICATION_EVENTS = [
        'lead.assigned', 'task.overdue', 'conversation.received', 'quote.viewed',
        'opportunity.stalled', 'ticket.urgent', 'goal.completed',
        'import.completed', 'export.ready',
    ];

    private function __construct() {}
}
