<?php

namespace App\Services;

use App\Jobs\DeliverConversationMessageJob;
use App\Models\Conversation;
use App\Models\FileRecord;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessageTransportManager;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final class ConversationMessageService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MessageTransportManager $transports,
        private readonly HtmlSanitizer $html,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{message: Message, replayed: bool}
     */
    public function send(Conversation $conversation, User $sender, array $data, array $trustedMetadata = []): array
    {
        if (is_string(data_get($data, 'content.html'))) {
            $data['content']['html'] = $this->html->sanitize($data['content']['html']);
        }
        $transport = $this->transports->for($conversation->channel);
        $transport->assertConfigured($conversation);
        $scheduledAt = filled($data['scheduled_at'] ?? null)
            ? CarbonImmutable::parse((string) $data['scheduled_at'])
            : null;
        $scheduled = $scheduledAt?->isFuture() === true;

        if (filled($data['client_message_id'] ?? null)) {
            $existing = Message::query()->where('client_message_id', $data['client_message_id'])->first();
            if ($existing !== null) {
                if ((int) $existing->conversation_id !== (int) $conversation->id) {
                    throw ValidationException::withMessages([
                        'client_message_id' => 'This client message id belongs to another conversation.',
                    ]);
                }

                return ['message' => $existing->load($this->relations()), 'replayed' => true];
            }
        }

        $message = $this->database->transaction(function () use ($conversation, $sender, $data, $scheduledAt, $scheduled, $trustedMetadata): Message {
            $message = $conversation->messages()->create([
                'inbox_channel_id' => $conversation->inbox_channel_id,
                'sender_user_id' => $sender->id,
                'direction' => 'outbound',
                'sender_type' => 'user',
                'type' => $data['type'] ?? ($conversation->channel === 'email' ? 'email' : 'text'),
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'] ?? null,
                'content' => $data['content'] ?? null,
                'metadata' => $trustedMetadata === [] ? null : $trustedMetadata,
                'reply_to_id' => $data['reply_to_id'] ?? null,
                'client_message_id' => $data['client_message_id'] ?? null,
                'is_internal' => (bool) ($data['is_internal'] ?? false),
                'status' => $scheduled ? 'scheduled' : 'queued',
                'occurred_at' => now(),
                'scheduled_at' => $scheduled ? $scheduledAt : null,
            ]);

            $files = FileRecord::query()->whereIn('id', $data['file_ids'] ?? [])->get();
            foreach ($files as $file) {
                $message->attachments()->create([
                    'file_record_id' => $file->id,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type,
                    'size' => $file->size,
                ]);
            }

            $conversation->update([
                'last_message_at' => $message->occurred_at,
                'last_outbound_at' => $scheduled ? $conversation->last_outbound_at : $message->occurred_at,
            ]);
            $this->audit->record($scheduled ? 'message_scheduled' : 'message_queued', $message, newValues: [
                'conversation_id' => $conversation->id,
                'channel' => $conversation->channel,
                'type' => $message->type,
                'attachment_count' => $files->count(),
                'scheduled_at' => $message->scheduled_at?->toISOString(),
            ]);

            return $message;
        });

        if (! $scheduled) {
            DeliverConversationMessageJob::dispatch((int) $conversation->tenant_id, (int) $message->id)->afterCommit();
        }

        return ['message' => $message->fresh($this->relations()), 'replayed' => false];
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['senderUser:id,name', 'senderContact:id,first_name,last_name', 'attachments.file'];
    }
}
