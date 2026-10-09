<?php

namespace App\Services\Email;

use App\Events\ConversationMessageUpdated;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\EmailAccount;
use App\Models\FileRecord;
use App\Models\Message;
use App\Services\DuplicateNormalizer;
use App\Services\HtmlSanitizer;
use App\Services\NotificationDispatcher;
use App\Services\TimelineEventRecorder;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class EmailInboundService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly DuplicateNormalizer $normalizer,
        private readonly HtmlSanitizer $html,
        private readonly NotificationDispatcher $notifications,
        private readonly TimelineEventRecorder $timeline,
    ) {}

    /** @param array<int, array<string, mixed>> $providerMessages */
    public function ingest(EmailAccount $account, array $providerMessages): int
    {
        $account->loadMissing('channel.inbox');
        $created = 0;
        foreach ($providerMessages as $providerMessage) {
            if (! is_array($providerMessage)) {
                continue;
            }
            $result = $this->ingestOne($account, $providerMessage);
            if ($result === null) {
                continue;
            }
            $created++;
            $message = $result['message'];
            $conversation = $result['conversation'];
            $contact = $result['contact'];
            if ($contact !== null) {
                $this->timeline->record($contact, $message->direction === 'inbound' ? 'email.received' : 'email.sent', [
                    'message_id' => (int) $message->id,
                    'conversation_id' => (int) $conversation->id,
                    'provider' => $account->provider,
                ]);
            }
            if ($message->direction === 'inbound' && $conversation->assignee !== null) {
                $this->notifications->send(
                    $conversation->assignee,
                    'conversation.received',
                    'Nuevo correo recibido',
                    Str::limit((string) ($message->subject ?: $message->body ?: 'Tienes un nuevo correo.'), 180),
                    ['conversation_id' => (int) $conversation->id, 'message_id' => (int) $message->id],
                    '/conversations/'.$conversation->id,
                );
            }
            ConversationMessageUpdated::dispatch($message);
        }

        return $created;
    }

    /** @param array<string, mixed> $data
     * @return array{message: Message, conversation: Conversation, contact: ?Contact}|null
     */
    private function ingestOne(EmailAccount $account, array $data): ?array
    {
        $externalId = mb_substr(trim((string) ($data['external_id'] ?? '')), 0, 190);
        if ($externalId === '') {
            return null;
        }
        if (Message::query()->where('inbox_channel_id', $account->inbox_channel_id)
            ->where('external_message_id', $externalId)->exists()) {
            return null;
        }
        $direction = ($data['direction'] ?? null) === 'outbound' ? 'outbound' : 'inbound';
        $from = $this->normalizer->email($data['from_email'] ?? null);
        $recipients = array_values(array_filter(array_map(
            fn ($value): ?string => $this->normalizer->email($value),
            is_array($data['to'] ?? null) ? $data['to'] : [],
        )));
        $counterpart = $direction === 'inbound'
            ? $from
            : collect($recipients)->first(fn (string $email): bool => $email !== mb_strtolower($account->email_address));
        $contact = $counterpart === null
            ? null
            : Contact::query()->where('email_normalized', $counterpart)->first();
        $threadId = mb_substr(trim((string) ($data['thread_id'] ?? $externalId)), 0, 190) ?: $externalId;
        try {
            $occurredAt = CarbonImmutable::parse((string) ($data['occurred_at'] ?? 'now'));
        } catch (Throwable) {
            $occurredAt = CarbonImmutable::now();
        }

        return $this->database->transaction(function () use (
            $account,
            $data,
            $externalId,
            $direction,
            $counterpart,
            $contact,
            $threadId,
            $occurredAt,
        ): ?array {
            $duplicate = Message::query()->where('inbox_channel_id', $account->inbox_channel_id)
                ->where('external_message_id', $externalId)->lockForUpdate()->exists();
            if ($duplicate) {
                return null;
            }
            $inbox = $account->channel->inbox;
            $conversation = Conversation::query()
                ->where('inbox_channel_id', $account->inbox_channel_id)
                ->where('external_identifier', $threadId)
                ->lockForUpdate()->first();
            $conversation ??= Conversation::create([
                'inbox_id' => $inbox->id,
                'inbox_channel_id' => $account->inbox_channel_id,
                'contact_id' => $contact?->id,
                'assigned_user_id' => $inbox->default_assignee_id,
                'assigned_role_id' => $inbox->default_role_id,
                'channel' => 'email',
                'subject' => $this->subject($data['subject'] ?? null),
                'external_identifier' => $threadId,
                'status' => 'open',
                'priority' => 'normal',
            ]);
            $conversation->loadMissing('assignee');
            $conversationUpdates = [];
            if ($conversation->contact_id === null && $contact !== null) {
                $conversationUpdates['contact_id'] = $contact->id;
            }
            if (blank($conversation->subject) && filled($data['subject'] ?? null)) {
                $conversationUpdates['subject'] = $this->subject($data['subject']);
            }
            if ($counterpart !== null) {
                $conversation->participants()->firstOrCreate([
                    'type' => $contact === null ? 'external' : 'contact',
                    'external_identifier' => $counterpart,
                ], [
                    'contact_id' => $contact?->id,
                    'role' => 'customer',
                    'display_name' => mb_substr(trim((string) ($data['from_name'] ?? '')), 0, 190) ?: null,
                ]);
            }
            $html = is_string($data['html'] ?? null) ? $this->html->sanitize($data['html']) : null;
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $metadata = array_replace($metadata, [
                'internet_message_id' => $data['internet_message_id'] ?? null,
                'in_reply_to' => $data['in_reply_to'] ?? null,
            ]);
            $message = $conversation->messages()->create([
                'inbox_channel_id' => $account->inbox_channel_id,
                'sender_contact_id' => $direction === 'inbound' ? $contact?->id : null,
                'direction' => $direction,
                'sender_type' => $direction === 'inbound' ? ($contact === null ? 'external' : 'contact') : 'account',
                'type' => 'email',
                'subject' => $this->subject($data['subject'] ?? null),
                'body' => mb_substr((string) ($data['text'] ?? ''), 0, 100000) ?: null,
                'content' => $html === null ? null : ['html' => $html],
                'metadata' => $metadata,
                'status' => $direction === 'inbound' ? 'received' : 'sent',
                'external_message_id' => $externalId,
                'is_internal' => false,
                'occurred_at' => $occurredAt,
                'sent_at' => $direction === 'outbound' ? $occurredAt : null,
            ]);
            foreach ((array) ($data['attachments'] ?? []) as $attachment) {
                if (is_array($attachment)) {
                    $this->attach($message, $attachment);
                }
            }
            $lastMessageAt = $conversation->last_message_at;
            if ($lastMessageAt === null || $lastMessageAt->lessThan($occurredAt)) {
                $conversationUpdates['last_message_at'] = $occurredAt;
            }
            $directionField = $direction === 'inbound' ? 'last_inbound_at' : 'last_outbound_at';
            $lastDirectionAt = $conversation->{$directionField};
            if ($lastDirectionAt === null || $lastDirectionAt->lessThan($occurredAt)) {
                $conversationUpdates[$directionField] = $occurredAt;
            }
            if ($direction === 'inbound' && in_array($conversation->status, ['resolved', 'closed'], true)) {
                $conversationUpdates = array_replace($conversationUpdates, [
                    'status' => 'open', 'resolved_at' => null, 'closed_at' => null,
                ]);
            }
            if ($conversationUpdates !== []) {
                $conversation->update($conversationUpdates);
            }

            return [
                'message' => $message->load('attachments.file'),
                'conversation' => $conversation->fresh('assignee'),
                'contact' => $contact,
            ];
        });
    }

    /** @param array<string, mixed> $attachment */
    private function attach(Message $message, array $attachment): void
    {
        $filename = mb_substr(trim(basename(str_replace('\\', '/', (string) ($attachment['filename'] ?? 'attachment')))), 0, 255);
        $filename = $filename !== '' ? $filename : 'attachment';
        $mimeType = mb_substr((string) ($attachment['mime_type'] ?? 'application/octet-stream'), 0, 190);
        $size = max(0, (int) ($attachment['size'] ?? 0));
        $content = is_string($attachment['content'] ?? null) ? $attachment['content'] : null;
        $file = null;
        if ($content !== null) {
            $size = strlen($content);
            $disk = (string) config('filesystems.default');
            $extension = preg_replace('/[^A-Za-z0-9]+/', '', (string) pathinfo($filename, PATHINFO_EXTENSION));
            $path = 'tenants/'.app(TenantContext::class)->requireId().'/messages/'.Str::uuid()
                .($extension !== '' ? '.'.mb_strtolower($extension) : '');
            if (! Storage::disk($disk)->put($path, $content)) {
                throw new \RuntimeException('The inbound email attachment could not be stored.');
            }
            $file = FileRecord::create([
                'disk' => $disk,
                'path' => $path,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size' => $size,
                'uploaded_by' => null,
                'related_type' => null,
                'related_id' => null,
                'metadata' => ['source' => 'inbound_email'],
            ]);
        }
        $message->attachments()->create([
            'file_record_id' => $file?->id,
            'provider_attachment_id' => mb_substr((string) ($attachment['external_id'] ?? ''), 0, 190) ?: null,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'metadata' => [
                'downloaded' => $file !== null,
                'inline' => (bool) ($attachment['inline'] ?? false),
            ],
        ]);
    }

    private function subject(mixed $subject): ?string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', (string) $subject));

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
