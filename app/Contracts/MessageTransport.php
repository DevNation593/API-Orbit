<?php

namespace App\Contracts;

use App\Models\Conversation;
use App\Models\Message;

interface MessageTransport
{
    public function supports(string $channel): bool;

    public function assertConfigured(Conversation $conversation): void;

    /** @return array{status: string, external_message_id?: string|null, metadata?: array<string, mixed>} */
    public function deliver(Message $message): array;
}
