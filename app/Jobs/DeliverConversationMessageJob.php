<?php

namespace App\Jobs;

use App\Events\ConversationMessageUpdated;
use App\Models\CampaignMember;
use App\Models\Message;
use App\Services\CampaignTelemetryService;
use App\Services\MarketingMessageGuard;
use App\Services\Messaging\MessageTransportManager;
use App\Services\TimelineEventRecorder;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverConversationMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->messageId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('deliver-message:'.$this->tenantId.':'.$this->messageId))->releaseAfter(10)->expireAfter(180)];
    }

    public function __construct(public readonly int $tenantId, public readonly int $messageId)
    {
        $this->onQueue('integrations');
    }

    public function handle(MessageTransportManager $transports, TimelineEventRecorder $timeline): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);

        try {
            $message = Message::query()->with(['conversation.contact', 'attachments.file'])->findOrFail($this->messageId);
            if ($message->status === 'scheduled' && $message->scheduled_at?->isFuture()) {
                return;
            }
            if (! in_array($message->status, ['scheduled', 'queued', 'failed'], true)) {
                if (data_get($message->metadata, 'marketing.campaign_member_id') !== null && in_array($message->status, ['sent', 'delivered', 'read'], true)) {
                    $telemetry = app(CampaignTelemetryService::class);
                    $telemetry->forMessage($message, 'sent');
                    if (in_array($message->status, ['delivered', 'read'], true)) {
                        $telemetry->forMessage($message, 'delivered');
                    }
                }

                return;
            }
            if (! app(MarketingMessageGuard::class)->canDeliver($message)) {
                return;
            }

            if ($message->status === 'scheduled') {
                $message->update(['status' => 'queued']);
            }

            $result = $transports->for($message->conversation->channel)->deliver($message);
            $message->update([
                'status' => $result['status'],
                'external_message_id' => $result['external_message_id'] ?? $message->external_message_id,
                'metadata' => array_replace($message->metadata ?? [], $result['metadata'] ?? []),
                'sent_at' => in_array($result['status'], ['sent', 'delivered', 'read'], true) ? now() : $message->sent_at,
                'failed_at' => null,
            ]);
            $lastMessageAt = $message->conversation->last_message_at;
            $message->conversation->update([
                'last_message_at' => $lastMessageAt === null || $lastMessageAt->lessThan($message->sent_at)
                    ? $message->sent_at
                    : $lastMessageAt,
                'last_outbound_at' => $message->sent_at,
            ]);
            if ($message->conversation->first_response_at === null && $message->conversation->last_inbound_at !== null) {
                $message->conversation->update(['first_response_at' => now()]);
            }
            if ($message->conversation->contact !== null) {
                $timeline->record($message->conversation->contact, $message->conversation->channel.'.sent', [
                    'message_id' => (int) $message->id,
                    'conversation_id' => (int) $message->conversation_id,
                    'status' => $message->status,
                ]);
            }
            ConversationMessageUpdated::dispatch($message->fresh());
        } catch (Throwable $exception) {
            $failed = Message::query()->find($this->messageId);
            // A local tracking/timeline error after the accepted send must not trigger another delivery.
            if ($failed !== null && in_array($failed->status, ['sent', 'delivered', 'read'], true)) {
                throw $exception;
            }
            $failed?->update([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_replace($failed->metadata ?? [], [
                    'delivery_error' => mb_substr($exception->getMessage(), 0, 500),
                ]),
            ]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        try {
            $message = Message::find($this->messageId);
            $memberId = data_get($message?->metadata, 'marketing.campaign_member_id');
            if ($memberId !== null && $message?->status === 'failed') {
                CampaignMember::whereKey($memberId)->where('status', 'queued')
                    ->update(['status' => 'failed', 'skip_reason' => 'delivery_attempts_exhausted']);
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
