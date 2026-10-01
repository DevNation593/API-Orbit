<?php

namespace App\Jobs;

use App\Events\ConversationMessageUpdated;
use App\Models\ChannelWebhookEvent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\NotificationDispatcher;
use App\Services\TimelineEventRecorder;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $accountId,
        public readonly int $eventId,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(NotificationDispatcher $notifications, TimelineEventRecorder $timeline): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        $event = null;
        try {
            $event = ChannelWebhookEvent::findOrFail($this->eventId);
            if ($event->status === 'processed') {
                return;
            }
            $event->increment('attempts');
            $account = WhatsAppAccount::with(['channel.inbox'])->findOrFail($this->accountId);
            foreach ((array) data_get($event->payload, 'entry', []) as $entry) {
                foreach ((array) data_get($entry, 'changes', []) as $change) {
                    $value = (array) ($change['value'] ?? []);
                    foreach ((array) ($value['messages'] ?? []) as $message) {
                        $this->ingestMessage($account, $value, $message, $notifications, $timeline);
                    }
                    foreach ((array) ($value['statuses'] ?? []) as $status) {
                        $this->ingestStatus($status);
                    }
                }
            }
            $event->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        } catch (Throwable $exception) {
            $event?->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    /** @param array<string, mixed> $value
     * @param  array<string, mixed>  $providerMessage
     */
    private function ingestMessage(
        WhatsAppAccount $account,
        array $value,
        array $providerMessage,
        NotificationDispatcher $notifications,
        TimelineEventRecorder $timeline,
    ): void {
        $externalId = (string) ($providerMessage['id'] ?? '');
        $from = preg_replace('/\D+/', '', (string) ($providerMessage['from'] ?? ''));
        if ($externalId === '' || $from === '') {
            return;
        }
        if (Message::query()->where('inbox_channel_id', $account->inbox_channel_id)
            ->where('external_message_id', $externalId)->exists()) {
            return;
        }

        $contact = Contact::query()->where('phone_normalized', $from)->first();
        $inbox = $account->channel->inbox;
        $conversation = Conversation::query()->where('inbox_channel_id', $account->inbox_channel_id)
            ->where(function ($query) use ($from, $contact): void {
                $query->where('external_identifier', $from);
                if ($contact !== null) {
                    $query->orWhere('contact_id', $contact->id);
                }
            })->latest('last_message_at')->first();
        $conversation ??= Conversation::create([
            'inbox_id' => $inbox->id,
            'inbox_channel_id' => $account->inbox_channel_id,
            'contact_id' => $contact?->id,
            'assigned_user_id' => $inbox->default_assignee_id,
            'assigned_role_id' => $inbox->default_role_id,
            'channel' => 'whatsapp',
            'external_identifier' => $from,
            'status' => 'open',
            'priority' => 'normal',
        ]);
        if ($conversation->contact_id === null || $conversation->external_identifier === null) {
            $conversation->update([
                'contact_id' => $conversation->contact_id ?? $contact?->id,
                'external_identifier' => $conversation->external_identifier ?? $from,
            ]);
        }
        $profileName = data_get($value, 'contacts.0.profile.name');
        $conversation->participants()->firstOrCreate([
            'type' => $contact === null ? 'external' : 'contact',
            'external_identifier' => $from,
        ], [
            'contact_id' => $contact?->id,
            'role' => 'customer',
            'display_name' => is_scalar($profileName) ? (string) $profileName : null,
        ]);

        $type = (string) ($providerMessage['type'] ?? 'text');
        [$body, $content] = $this->messageContent($type, $providerMessage);
        $occurredAt = isset($providerMessage['timestamp'])
            ? CarbonImmutable::createFromTimestampUTC((int) $providerMessage['timestamp']) : now();
        $message = $conversation->messages()->create([
            'inbox_channel_id' => $account->inbox_channel_id,
            'sender_contact_id' => $contact?->id,
            'direction' => 'inbound',
            'sender_type' => $contact === null ? 'external' : 'contact',
            'type' => in_array($type, ['text', 'image', 'video', 'document', 'audio', 'template'], true) ? $type : 'system',
            'body' => $body,
            'content' => $content,
            'status' => 'received',
            'external_message_id' => $externalId,
            'occurred_at' => $occurredAt,
        ]);
        $conversation->update([
            'status' => in_array($conversation->status, ['resolved', 'closed'], true) ? 'open' : $conversation->status,
            'last_message_at' => $occurredAt,
            'last_inbound_at' => $occurredAt,
            'resolved_at' => null,
            'closed_at' => null,
        ]);
        if ($contact !== null) {
            $timeline->record($contact, 'whatsapp.received', [
                'message_id' => (int) $message->id,
                'conversation_id' => (int) $conversation->id,
            ]);
        }
        $assignee = $conversation->assignee;
        if ($assignee !== null) {
            $notifications->send(
                $assignee,
                'conversation.received',
                'Nuevo mensaje de WhatsApp',
                is_string($profileName) ? 'Mensaje recibido de '.$profileName : 'Tienes un nuevo mensaje de WhatsApp.',
                ['conversation_id' => (int) $conversation->id, 'message_id' => (int) $message->id],
                '/conversations/'.$conversation->id,
                'high',
            );
        }
        ConversationMessageUpdated::dispatch($message);
    }

    /** @param array<string, mixed> $status */
    private function ingestStatus(array $status): void
    {
        $externalId = (string) ($status['id'] ?? '');
        $state = (string) ($status['status'] ?? '');
        if ($externalId === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $message = Message::query()->where('external_message_id', $externalId)->first();
        if ($message === null) {
            return;
        }
        $at = isset($status['timestamp']) ? CarbonImmutable::createFromTimestampUTC((int) $status['timestamp']) : now();
        $updates = ['status' => $state];
        $updates[$state.'_at'] = $at;
        if ($state === 'failed') {
            $updates['metadata'] = array_replace($message->metadata ?? [], ['provider_errors' => $status['errors'] ?? []]);
        }
        $message->update($updates);
        ConversationMessageUpdated::dispatch($message->fresh());
    }

    /** @param array<string, mixed> $message
     * @return array{0: ?string, 1: array<string, mixed>|null}
     */
    private function messageContent(string $type, array $message): array
    {
        if ($type === 'text') {
            return [data_get($message, 'text.body'), null];
        }
        $content = is_array($message[$type] ?? null) ? $message[$type] : [];
        $body = $content['caption'] ?? null;

        return [is_scalar($body) ? (string) $body : null, [$type => $content]];
    }
}
