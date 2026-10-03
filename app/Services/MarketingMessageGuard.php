<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\JourneyEnrollment;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TenantUser;

final class MarketingMessageGuard
{
    public function __construct(private readonly SegmentEngine $entities, private readonly ConsentService $consent) {}

    public function canDeliver(Message $message): bool
    {
        $marketing = data_get($message->metadata, 'marketing');
        if (! is_array($marketing)) {
            return true;
        }
        $campaign = isset($marketing['campaign_id']) ? Campaign::find($marketing['campaign_id']) : null;
        $enrollment = isset($marketing['journey_enrollment_id']) ? JourneyEnrollment::with('journey')->find($marketing['journey_enrollment_id']) : null;
        if ($campaign?->status === 'paused' || $enrollment?->status === 'paused' || $enrollment?->journey?->status === 'paused') {
            return false;
        }
        $subject = $this->entities->base($marketing['entity_type'])->find($marketing['entity_id']);
        $cancelled = (isset($marketing['campaign_id']) && ($campaign === null || $campaign->status === 'cancelled'))
            || (isset($marketing['journey_enrollment_id']) && ($enrollment === null || $enrollment->journey?->status === 'archived' || in_array($enrollment->status, ['cancelled', 'failed'], true)))
            || ! Tenant::whereKey($message->tenant_id)->where('status', 'active')->exists();
        $senderActive = TenantUser::where('tenant_id', $message->tenant_id)->where('user_id', $message->sender_user_id)->where('status', 'active')->exists();
        if ($cancelled || ! $senderActive || $subject === null || ! $this->consent->allowed($subject, $marketing['channel'], $marketing['destination'])) {
            $message->update(['status' => 'cancelled']);
            if (isset($marketing['campaign_member_id'])) {
                CampaignMember::whereKey($marketing['campaign_member_id'])->update(['status' => 'skipped', 'skip_reason' => 'delivery_suppressed']);
            }

            return false;
        }

        return true;
    }
}
