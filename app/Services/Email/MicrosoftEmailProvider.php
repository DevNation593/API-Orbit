<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MicrosoftEmailProvider implements EmailProviderInterface
{
    public function supports(string $provider): bool
    {
        return $provider === 'microsoft';
    }

    public function send(EmailAccount $account, Message $message, string $recipient): array
    {
        $html = data_get($message->content, 'html');
        $attachments = $message->attachments->map(function ($attachment): ?array {
            $file = $attachment->file;
            if ($file === null) {
                return null;
            }

            return [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment->filename,
                'contentType' => $attachment->mime_type,
                'contentBytes' => base64_encode(Storage::disk($file->disk)->get($file->path)),
            ];
        })->filter()->values()->all();
        $payload = [
            'message' => [
                'subject' => $message->subject ?: '(Sin asunto)',
                'body' => [
                    'contentType' => is_string($html) ? 'HTML' : 'Text',
                    'content' => is_string($html) ? $html.$account->signature_html : (string) $message->body,
                ],
                'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
                'attachments' => $attachments,
            ],
            'saveToSentItems' => true,
        ];
        $response = $this->request($account)
            ->post('https://graph.microsoft.com/v1.0/me/sendMail', $payload);
        if ($response->status() !== 202) {
            throw new RuntimeException('Microsoft Graph rejected the message with HTTP '.$response->status().'.');
        }

        return [
            'status' => 'sent',
            'external_message_id' => null,
            'metadata' => ['provider' => 'microsoft', 'request_id' => $response->header('request-id')],
        ];
    }

    public function sync(EmailAccount $account, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $cursor = $account->sync_cursor;
        $url = filled($cursor)
            ? $this->trustedDeltaUrl((string) $cursor)
            : 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta';
        $params = filled($cursor) ? [] : [
            '$select' => implode(',', [
                'id', 'conversationId', 'internetMessageId', 'subject', 'body', 'bodyPreview',
                'from', 'toRecipients', 'ccRecipients', 'receivedDateTime', 'sentDateTime',
                'hasAttachments', 'isRead', 'importance',
            ]),
            '$top' => $limit,
        ];
        $response = $this->request($account)
            ->withHeaders(['Prefer' => 'odata.maxpagesize='.$limit])
            ->get($url, $params);
        $this->assertSuccessful($response, 'delta synchronization');

        $messages = [];
        foreach ((array) $response->json('value', []) as $providerMessage) {
            if (! is_array($providerMessage) || isset($providerMessage['@removed'])) {
                continue;
            }
            $normalized = $this->normalizeMessage($account, $providerMessage);
            if ($normalized !== null) {
                $messages[] = $normalized;
            }
        }
        $payload = (array) $response->json();
        $next = $payload['@odata.nextLink'] ?? null;
        $delta = $payload['@odata.deltaLink'] ?? null;
        $newCursor = is_string($next) && $next !== '' ? $this->trustedDeltaUrl($next) : null;
        $newCursor ??= is_string($delta) && $delta !== '' ? $this->trustedDeltaUrl($delta) : $cursor;

        return [
            'messages' => array_slice($messages, 0, $limit),
            'cursor' => $newCursor,
            'has_more' => filled($next),
        ];
    }

    /** @param array<string, mixed> $providerMessage
     * @return array<string, mixed>|null
     */
    private function normalizeMessage(EmailAccount $account, array $providerMessage): ?array
    {
        $externalId = (string) ($providerMessage['id'] ?? '');
        if ($externalId === '') {
            return null;
        }
        $fromEmail = mb_strtolower((string) data_get($providerMessage, 'from.emailAddress.address', ''));
        $fromName = (string) data_get($providerMessage, 'from.emailAddress.name', '');
        $to = collect((array) ($providerMessage['toRecipients'] ?? []))
            ->map(fn (array $recipient): string => mb_strtolower((string) data_get($recipient, 'emailAddress.address', '')))
            ->filter()->unique()->values()->all();
        $bodyType = strtolower((string) data_get($providerMessage, 'body.contentType', 'text'));
        $body = (string) data_get($providerMessage, 'body.content', $providerMessage['bodyPreview'] ?? '');
        $occurredAt = (string) ($providerMessage['receivedDateTime'] ?? $providerMessage['sentDateTime'] ?? now()->toISOString());
        try {
            $occurredAt = CarbonImmutable::parse($occurredAt)->toISOString();
        } catch (\Throwable) {
            $occurredAt = now()->toISOString();
        }

        return [
            'external_id' => $externalId,
            'thread_id' => (string) ($providerMessage['conversationId'] ?? $externalId),
            'internet_message_id' => $providerMessage['internetMessageId'] ?? null,
            'in_reply_to' => null,
            'direction' => $fromEmail === mb_strtolower($account->email_address) ? 'outbound' : 'inbound',
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to' => $to,
            'subject' => $providerMessage['subject'] ?? null,
            'text' => $bodyType === 'html' ? (string) ($providerMessage['bodyPreview'] ?? strip_tags($body)) : $body,
            'html' => $bodyType === 'html' ? $body : null,
            'occurred_at' => $occurredAt,
            'attachments' => ! empty($providerMessage['hasAttachments'])
                ? $this->attachments($account, $externalId)
                : [],
            'metadata' => [
                'provider' => 'microsoft',
                'is_read' => (bool) ($providerMessage['isRead'] ?? false),
                'importance' => $providerMessage['importance'] ?? null,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function attachments(EmailAccount $account, string $messageId): array
    {
        $response = $this->request($account)->get(
            'https://graph.microsoft.com/v1.0/me/messages/'.rawurlencode($messageId).'/attachments',
            ['$select' => 'id,name,contentType,size,contentBytes,isInline'],
        );
        $this->assertSuccessful($response, 'attachment synchronization');
        $maxBytes = max(1, min(25 * 1024 * 1024, (int) data_get($account->settings, 'max_attachment_bytes', 25 * 1024 * 1024)));

        return collect((array) $response->json('value', []))->filter(fn ($attachment): bool => is_array($attachment))
            ->map(function (array $attachment) use ($maxBytes): array {
                $size = max(0, (int) ($attachment['size'] ?? 0));
                $content = is_string($attachment['contentBytes'] ?? null)
                    ? base64_decode($attachment['contentBytes'], true)
                    : false;

                return [
                    'external_id' => is_string($attachment['id'] ?? null) ? $attachment['id'] : null,
                    'filename' => trim(basename(str_replace('\\', '/', (string) ($attachment['name'] ?? 'attachment')))),
                    'mime_type' => (string) ($attachment['contentType'] ?? 'application/octet-stream'),
                    'size' => $size,
                    'content' => $content !== false && strlen($content) <= $maxBytes ? $content : null,
                    'inline' => (bool) ($attachment['isInline'] ?? false),
                ];
            })->values()->all();
    }

    private function request(EmailAccount $account): PendingRequest
    {
        $token = (string) data_get($account->integration->credentials, 'access_token');
        if ($account->integration->status !== 'active' || $token === '') {
            throw new RuntimeException('The Microsoft email integration is not connected.');
        }

        return Http::acceptJson()->withToken($token)->timeout(30)->connectTimeout(10);
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("Microsoft Graph {$operation} failed with HTTP {$response->status()}.");
        }
    }

    private function trustedDeltaUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https'
            || mb_strtolower((string) ($parts['host'] ?? '')) !== 'graph.microsoft.com'
            || ! str_starts_with((string) ($parts['path'] ?? ''), '/v1.0/')) {
            throw new RuntimeException('Microsoft returned an invalid delta cursor URL.');
        }

        return $url;
    }
}
