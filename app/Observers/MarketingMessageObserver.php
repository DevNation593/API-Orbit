<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\CampaignTelemetryService;

class MarketingMessageObserver
{
    public function __construct(private readonly CampaignTelemetryService $telemetry) {}

    public function updated(Message $message): void
    {
        if (! $message->wasChanged('status') || data_get($message->metadata, 'marketing.campaign_member_id') === null) {
            return;
        }
        if (in_array($message->status, ['sent', 'delivered', 'read'], true)) {
            $this->telemetry->forMessage($message, 'sent');
        }
        if (in_array($message->status, ['delivered', 'read'], true)) {
            $this->telemetry->forMessage($message, 'delivered');
        }
        if ($message->status === 'failed') {
            $this->telemetry->forMessage($message, 'failed');
        }
    }
}
