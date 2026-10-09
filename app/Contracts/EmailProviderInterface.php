<?php

namespace App\Contracts;

use App\Models\EmailAccount;
use App\Models\Message;

interface EmailProviderInterface
{
    public function supports(string $provider): bool;

    /** @return array{status: string, external_message_id?: string|null, metadata?: array<string, mixed>} */
    public function send(EmailAccount $account, Message $message, string $recipient): array;

    /**
     * Return provider-neutral mailbox changes. The cursor is opaque to callers
     * and must only be persisted on the encrypted EmailAccount.sync_cursor.
     *
     * @return array{messages: array<int, array<string, mixed>>, cursor: ?string, has_more: bool}
     */
    public function sync(EmailAccount $account, int $limit = 100): array;
}
