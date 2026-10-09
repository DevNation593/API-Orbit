<?php

namespace App\Services\Messaging;

use App\Contracts\MessageTransport;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class WhatsAppMessageTransport implements MessageTransport
{
    public function supports(string $channel): bool
    {
        return $channel === 'whatsapp';
    }

    public function assertConfigured(Conversation $conversation): void
    {
        $this->account($conversation);
        $this->recipient($conversation, null);
    }

    public function deliver(Message $message): array
    {
        $account = $this->account($message->conversation);
        $credentials = $account->integration->credentials ?? [];
        $version = (string) data_get($account->integration->settings, 'api_version');
        $payload = $this->payload($message, $this->recipient($message->conversation, $message));
        $response = Http::acceptJson()->withToken((string) ($credentials['access_token'] ?? ''))
            ->timeout(30)->post(
                "https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages",
                $payload,
            );
        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp Cloud API rejected the message with HTTP '.$response->status().'.');
        }

        return [
            'status' => 'sent',
            'external_message_id' => $response->json('messages.0.id'),
            'metadata' => ['provider' => 'whatsapp_cloud', 'wa_id' => $response->json('contacts.0.wa_id')],
        ];
    }

    private function account(Conversation $conversation): WhatsAppAccount
    {
        $account = WhatsAppAccount::query()->with('integration')
            ->where('inbox_channel_id', $conversation->inbox_channel_id)->where('status', 'active')->first();
        if ($account === null || $account->integration?->status !== 'active') {
            throw ValidationException::withMessages(['channel' => 'The conversation has no connected WhatsApp account.']);
        }

        return $account;
    }

    private function recipient(Conversation $conversation, ?Message $message): string
    {
        $recipient = data_get($message?->content, 'to') ?? $conversation->contact?->phone
            ?? $conversation->external_identifier;
        $recipient = preg_replace('/\D+/', '', (string) $recipient);
        if ($recipient === '') {
            throw ValidationException::withMessages(['recipient' => 'The conversation has no valid WhatsApp recipient.']);
        }

        return $recipient;
    }

    /** @return array<string, mixed> */
    private function payload(Message $message, string $recipient): array
    {
        $type = $message->type === 'email' ? 'text' : $message->type;
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => $type,
        ];
        if ($message->replyTo?->external_message_id !== null) {
            $payload['context'] = ['message_id' => $message->replyTo->external_message_id];
        }
        if ($type === 'text') {
            $payload['text'] = ['preview_url' => false, 'body' => (string) $message->body];

            return $payload;
        }
        if ($type === 'template') {
            $template = data_get($message->content, 'template');
            if (! is_array($template) || blank($template['name'] ?? null) || blank(data_get($template, 'language.code'))) {
                throw ValidationException::withMessages(['content.template' => 'A template name and language code are required.']);
            }
            $payload['template'] = $template;

            return $payload;
        }
        if (in_array($type, ['image', 'video', 'document', 'audio'], true)) {
            $media = data_get($message->content, $type);
            if (! is_array($media) || (! filled($media['id'] ?? null) && ! filled($media['link'] ?? null))) {
                throw ValidationException::withMessages(["content.$type" => 'A provider media id or public link is required.']);
            }
            $payload[$type] = $media;

            return $payload;
        }

        throw ValidationException::withMessages(['type' => 'This WhatsApp message type is not supported.']);
    }
}
