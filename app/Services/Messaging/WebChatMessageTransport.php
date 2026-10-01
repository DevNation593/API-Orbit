<?php

namespace App\Services\Messaging;

use App\Contracts\MessageTransport;
use App\Models\Conversation;
use App\Models\Message;

final class WebChatMessageTransport implements MessageTransport
{
    public function supports(string $channel): bool
    {
        return $channel === 'web_chat';
    }

    public function assertConfigured(Conversation $conversation): void {}

    public function deliver(Message $message): array
    {
        return ['status' => 'sent', 'external_message_id' => null];
    }
}
