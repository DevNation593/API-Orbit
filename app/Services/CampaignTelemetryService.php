<?php

namespace App\Services;

use App\Events\CampaignEventRecorded;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMember;
use App\Models\Message;
use App\Support\AuditService;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CampaignTelemetryService
{
    public const EVENTS = ['sent', 'delivered', 'opened', 'clicked', 'converted', 'bounced', 'failed', 'unsubscribed'];

    public function record(Campaign $campaign, CampaignMember $member, array $data): CampaignEvent
    {
        abort_unless((int) $member->campaign_id === (int) $campaign->id && (int) $member->tenant_id === (int) $campaign->tenant_id, 404);
        $data = Validator::make($data, [
            'event' => ['required', Rule::in(self::EVENTS)],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'revenue' => ['sometimes', 'string', 'regex:/^\\d{1,18}(\\.\\d{1,6})?$/'],
            'metadata' => ['nullable', 'array', 'max:20'],
        ])->validate();
        $revenue = Money::of($data['revenue'] ?? '0');
        abort_if($data['event'] !== 'converted' && Money::compare($revenue, '0') !== 0, 422, 'Only conversions can carry revenue.');
        $payload = ['campaign_member_id' => (int) $member->id, 'event' => $data['event'], 'revenue' => $revenue, 'metadata' => $data['metadata'] ?? []];
        $hash = hash('sha256', json_encode(Arr::sortRecursive($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($campaign, $data, $payload, $hash): CampaignEvent {
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $existing = $campaign->events()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'The event key was already used with a different payload.');

                return $existing;
            }
            $event = $campaign->events()->create($payload + [
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'occurred_at' => now(),
            ]);
            app(AuditService::class)->record('campaign_event_recorded', $event, newValues: [
                'campaign_id' => (int) $campaign->id, 'event' => $event->event, 'revenue' => $event->revenue,
            ]);
            CampaignEventRecorded::dispatch($event);

            return $event;
        });
    }

    public function forMessage(Message $message, string $event): void
    {
        $memberId = data_get($message->metadata, 'marketing.campaign_member_id');
        if ($memberId === null) {
            return;
        }
        $member = CampaignMember::find($memberId);
        if ($member === null || ((int) $member->message_id !== (int) $message->id && $member->message_id !== null)) {
            return;
        }
        $this->record($member->campaign, $member, [
            'event' => $event, 'idempotency_key' => 'message:'.$message->id.':'.$event,
        ]);
        if (in_array($event, ['sent', 'delivered', 'bounced'], true)) {
            $member->update(['status' => in_array($event, ['sent', 'delivered'], true) ? 'sent' : 'failed']);
        }
    }

    public function metrics(Campaign $campaign): array
    {
        $metrics = ['members' => $campaign->members()->count()];
        foreach (self::EVENTS as $event) {
            $metrics[$event] = $campaign->events()->where('event', $event)->distinct()->count('campaign_member_id');
        }
        $revenue = '0.000000';
        foreach ($campaign->events()->where('event', 'converted')->select('id', 'revenue')->lazyById(500) as $event) {
            $revenue = Money::add($revenue, $event->revenue);
        }

        return $metrics + [
            'pending' => $campaign->members()->whereIn('status', ['pending', 'queued'])->count(),
            'skipped' => $campaign->members()->where('status', 'skipped')->count(),
            'currency' => $campaign->currency, 'revenue' => $revenue, 'cost' => $campaign->cost,
            'roi' => Money::compare($campaign->cost, '0') === 0 ? null
                : Money::divide(Money::multiply(Money::subtract($revenue, $campaign->cost), '100'), $campaign->cost),
        ];
    }
}
