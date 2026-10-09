<?php

namespace App\Services;

use App\Jobs\DeliverConversationMessageJob;
use App\Jobs\ProcessMarketingJob;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\TenantUser;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CampaignService
{
    public function __construct(
        private readonly MarketingAudienceService $audiences,
        private readonly SegmentEngine $entities,
        private readonly ConsentService $consent,
        private readonly MarketingMessageService $messages,
        private readonly HtmlSanitizer $html,
        private readonly AuditService $audit,
    ) {}

    public function save(array $data, int $actorId, ?Campaign $campaign = null): Campaign
    {
        return DB::transaction(function () use ($data, $actorId, $campaign): Campaign {
            if ($campaign !== null) {
                $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                abort_unless($campaign->status === 'draft', 409, 'Only draft campaigns can be edited.');
            }
            $email = $data['email'];
            unset($data['email']);
            $audience = Audience::findOrFail($data['audience_id']);
            abort_unless($audience->active && in_array($audience->entity_type, ['contacts', 'leads'], true), 422, 'Choose an active contact or lead audience.');
            if (isset($email['body_html'])) {
                $email['body_html'] = $this->html->sanitize($email['body_html']);
            }
            if ($campaign === null) {
                $campaign = Campaign::create($data + ['public_id' => (string) Str::uuid(), 'created_by' => $actorId]);
            } else {
                $campaign->update($data);
            }
            $campaign->emailCampaign()->updateOrCreate([], $email);
            $this->audit->record('campaign_saved', $campaign);

            return $campaign->fresh(['emailCampaign', 'audience']);
        });
    }

    public function transition(Campaign $campaign, string $action): Campaign
    {
        return DB::transaction(function () use ($campaign, $action): Campaign {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $from = $campaign->status;
            $target = match ($action) {
                'launch' => $campaign->scheduled_at?->isFuture() ? 'scheduled' : 'sending',
                'pause' => 'paused', 'resume' => $campaign->scheduled_at?->isFuture() ? 'scheduled' : 'sending',
                'cancel' => 'cancelled',
            };
            // Replaying an action is safe and never rebuilds a launched audience snapshot.
            if ($from === $target || ($action === 'launch' && $campaign->launched_at !== null)) {
                return $campaign;
            }
            $allowed = match ($action) {
                'launch' => ['draft'], 'pause' => ['scheduled', 'sending'],
                'resume' => ['paused'], 'cancel' => ['draft', 'scheduled', 'sending', 'paused'],
            };
            abort_unless(in_array($from, $allowed, true), 409, 'Invalid campaign state transition.');
            if (in_array($action, ['launch', 'resume'], true)) {
                $this->audiences->query($campaign->audience);
                abort_unless(TenantUser::where('tenant_id', $campaign->tenant_id)->where('user_id', $campaign->sender_user_id)->where('status', 'active')->exists(), 409, 'The sender is inactive.');
                abort_unless($campaign->emailCampaign !== null, 422, 'An email campaign configuration is required.');
            }
            $campaign->update([
                'status' => $target, 'launched_at' => $action === 'launch' ? now() : $campaign->launched_at,
                'completed_at' => $action === 'cancel' ? now() : null,
            ]);
            if ($action === 'cancel') {
                $campaign->members()->whereIn('status', ['pending', 'queued'])->update(['status' => 'skipped', 'skip_reason' => 'campaign_cancelled']);
            }
            $this->audit->record('campaign_'.$action, $campaign, newValues: ['status' => $target]);
            if ($target === 'sending') {
                ProcessMarketingJob::dispatch((int) $campaign->tenant_id, 'campaign', (int) $campaign->id)->afterCommit();
            }

            return $campaign->fresh();
        });
    }

    public function process(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (! in_array($campaign->status, ['scheduled', 'sending'], true) || $campaign->scheduled_at?->isFuture()) {
                return;
            }
            $campaign->update(['status' => 'sending']);
            if ($campaign->prepared_at !== null) {
                return;
            }
            $audience = $campaign->audience;
            $this->audiences->query($audience)->chunkById(500, function ($subjects) use ($campaign, $audience): void {
                $rows = [];
                $now = now();
                foreach ($subjects as $subject) {
                    $destination = $this->consent->destination($subject, 'email');
                    $rows[] = [
                        'tenant_id' => $campaign->tenant_id, 'campaign_id' => $campaign->id,
                        'recipient_key' => hash('sha256', $destination ?? $audience->entity_type.':'.$subject->id),
                        'entity_type' => $audience->entity_type, 'entity_id' => $subject->id,
                        'destination' => $destination, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                DB::table('campaign_members')->insertOrIgnore($rows);
            });
            $campaign->update(['prepared_at' => now()]);
            $this->audit->record('campaign_prepared', $campaign, newValues: ['members' => $campaign->members()->count()]);
        });
        if ($campaign->fresh()->status !== 'sending') {
            return;
        }
        // Bounded work per run; the scheduler resumes any remaining members.
        foreach ($campaign->members()->where('status', 'pending')->orderBy('id')->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($campaign, $id): void {
                $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'sending') {
                    return;
                }
                $member = CampaignMember::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($member->status !== 'pending') {
                    return;
                }
                $subject = $this->entities->base($member->entity_type)->find($member->entity_id);
                if ($member->destination === null || $subject === null || ! $this->consent->allowed($subject, 'email', $member->destination)) {
                    $member->update(['status' => 'skipped', 'skip_reason' => 'missing_recipient_or_consent']);

                    return;
                }
                try {
                    $message = $this->messages->enqueue($subject, $member->entity_type, (int) $locked->sender_user_id,
                        $locked->emailCampaign->toArray(), 'campaign-member-'.$member->id,
                        ['campaign_id' => (int) $locked->id, 'campaign_member_id' => (int) $member->id]);
                    $member->update(['message_id' => $message->id, 'status' => 'queued']);
                } catch (ValidationException $exception) {
                    $member->update(['status' => 'failed', 'skip_reason' => 'invalid_delivery_configuration']);
                }
            });
        }
        foreach ($campaign->members()->where('status', 'queued')->whereNotNull('message_id')->orderBy('id')->limit(100)->get() as $member) {
            DeliverConversationMessageJob::dispatch((int) $campaign->tenant_id, (int) $member->message_id)->afterCommit();
        }
        DB::transaction(function () use ($campaign): void {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($campaign->status === 'sending' && ! $campaign->members()->whereIn('status', ['pending', 'queued'])->exists()) {
                $campaign->update(['status' => 'completed', 'completed_at' => now()]);
                $this->audit->record('campaign_completed', $campaign);
            }
        });
    }
}
