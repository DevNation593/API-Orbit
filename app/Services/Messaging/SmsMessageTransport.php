<?php

namespace App\Services\Messaging;

use App\Contracts\MessageTransport;
use App\Models\Conversation;
use App\Models\InboxChannel;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SmsMessageTransport implements MessageTransport
{
    public function supports(string $channel): bool
    {
        return $channel === 'sms';
    }

    public function assertConfigured(Conversation $conversation): void
    {
        $channel = $this->channel($conversation);
        $this->credentials($channel);
        $this->from($channel);
        $this->recipient($conversation, null);
    }

    public function deliver(Message $message): array
    {
        $channel = $this->channel($message->conversation);
        [$accountSid, $authToken] = $this->credentials($channel);
        $response = Http::asForm()->acceptJson()->withBasicAuth($accountSid, $authToken)
            ->timeout(30)->post(
                'https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($accountSid).'/Messages.json',
                [
                    'To' => $this->recipient($message->conversation, $message),
                    'From' => $this->from($channel),
                    'Body' => (string) $message->body,
                ],
            );
        if (! $response->successful()) {
            throw new RuntimeException('Twilio rejected the SMS with HTTP '.$response->status().'.');
        }

        return [
            'status' => 'sent',
            'external_message_id' => $response->json('sid'),
            'metadata' => [
                'provider' => 'twilio',
                'provider_status' => $response->json('status'),
            ],
        ];
    }

    private function channel(Conversation $conversation): InboxChannel
    {
        $channel = InboxChannel::query()->with('integration')->find($conversation->inbox_channel_id);
        if ($channel === null || $channel->channel !== 'sms' || $channel->status !== 'active'
            || $channel->integration?->provider !== 'twilio' || $channel->integration?->status !== 'active') {
            throw ValidationException::withMessages([
                'channel' => 'The conversation has no active Twilio SMS channel.',
            ]);
        }

        return $channel;
    }

    /** @return array{0: string, 1: string} */
    private function credentials(InboxChannel $channel): array
    {
        $accountSid = (string) data_get($channel->integration?->credentials, 'account_sid');
        $authToken = (string) data_get($channel->integration?->credentials, 'auth_token');
        if (! preg_match('/^AC[a-fA-F0-9]{32}$/', $accountSid) || $authToken === '') {
            throw ValidationException::withMessages(['channel' => 'The Twilio credentials are incomplete.']);
        }

        return [$accountSid, $authToken];
    }

    private function from(InboxChannel $channel): string
    {
        $from = $this->e164(data_get($channel->settings, 'from_number')
            ?? data_get($channel->integration?->settings, 'from_number'));
        if ($from === null) {
            throw ValidationException::withMessages(['channel' => 'The Twilio sender number is not configured.']);
        }

        return $from;
    }

    private function recipient(Conversation $conversation, ?Message $message): string
    {
        $recipient = $this->e164(data_get($message?->content, 'to')
            ?? $conversation->contact?->phone ?? $conversation->external_identifier);
        if ($recipient === null) {
            throw ValidationException::withMessages(['recipient' => 'The conversation has no valid E.164 SMS recipient.']);
        }

        return $recipient;
    }

    private function e164(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $number = preg_replace('/[\s().-]+/', '', trim((string) $value));

        return is_string($number) && preg_match('/^\+[1-9][0-9]{6,14}$/', $number) ? $number : null;
    }
}
