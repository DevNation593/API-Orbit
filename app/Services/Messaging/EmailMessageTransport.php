<?php

namespace App\Services\Messaging;

use App\Contracts\MessageTransport;
use App\Models\Conversation;
use App\Models\EmailAccount;
use App\Models\Message;
use App\Services\Email\EmailProviderManager;
use App\Services\EmailTrackingService;
use Illuminate\Validation\ValidationException;

final class EmailMessageTransport implements MessageTransport
{
    public function __construct(
        private readonly EmailProviderManager $providers,
        private readonly EmailTrackingService $tracking,
    ) {}

    public function supports(string $channel): bool
    {
        return $channel === 'email';
    }

    public function assertConfigured(Conversation $conversation): void
    {
        $account = $this->account($conversation);
        $this->recipient($conversation, null);
        $this->providers->for($account->provider);
    }

    public function deliver(Message $message): array
    {
        $account = $this->account($message->conversation);
        $message = $this->tracking->prepare($account, $message);

        return $this->providers->for($account->provider)
            ->send($account, $message, $this->recipient($message->conversation, $message));
    }

    private function account(Conversation $conversation): EmailAccount
    {
        $account = EmailAccount::query()->with('integration')
            ->where('inbox_channel_id', $conversation->inbox_channel_id)->where('status', 'active')->first();
        if ($account === null || $account->integration?->status !== 'active') {
            throw ValidationException::withMessages(['channel' => 'The conversation has no active email account.']);
        }

        return $account;
    }

    private function recipient(Conversation $conversation, ?Message $message): string
    {
        $pinned = data_get($message?->metadata, 'marketing.destination');
        $recipient = $pinned ?? $conversation->contact?->email ?? data_get($message?->content, 'to')
            ?? $conversation->participants()->where('type', 'external')->value('external_identifier')
            ?? $conversation->external_identifier;
        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient' => 'The conversation contact has no valid email address.']);
        }

        return $recipient;
    }
}
