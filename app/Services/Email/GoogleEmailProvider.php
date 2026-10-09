<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Throwable;

final class GoogleEmailProvider implements EmailProviderInterface
{
    public function __construct(private readonly EmailMimeBuilder $mime) {}

    public function supports(string $provider): bool
    {
        return $provider === 'google';
    }

    public function send(EmailAccount $account, Message $message, string $recipient): array
    {
        $request = $this->request($account);
        $mime = $this->mime->build($account, $message, $recipient)->toString();
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
        $response = $request->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);
        if (! $response->successful()) {
            throw new RuntimeException('Gmail rejected the message with HTTP '.$response->status().'.');
        }

        return [
            'status' => 'sent',
            'external_message_id' => $response->json('id'),
            'metadata' => ['provider' => 'google', 'thread_id' => $response->json('threadId')],
        ];
    }

    public function sync(EmailAccount $account, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $cursor = $this->decodeCursor($account->sync_cursor);

        if (($cursor['mode'] ?? null) === 'initial' || blank($cursor['history_id'] ?? null)) {
            return $this->initialSync($account, $limit, $cursor);
        }

        return $this->historySync($account, $limit, $cursor);
    }

    /** @param array<string, mixed> $cursor
     * @return array{messages: array<int, array<string, mixed>>, cursor: ?string, has_more: bool}
     */
    private function initialSync(EmailAccount $account, int $limit, array $cursor): array
    {
        $historyId = (string) ($cursor['history_id'] ?? '');
        if ($historyId === '') {
            $profile = $this->request($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');
            $this->assertSuccessful($profile, 'profile');
            $historyId = (string) $profile->json('historyId');
        }

        $params = [
            'maxResults' => $limit,
            'labelIds' => 'INBOX',
            'includeSpamTrash' => 'false',
        ];
        if (filled($cursor['page_token'] ?? null)) {
            $params['pageToken'] = (string) $cursor['page_token'];
        }
        $response = $this->request($account)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', $params);
        $this->assertSuccessful($response, 'message list');
        $ids = collect((array) $response->json('messages', []))
            ->pluck('id')->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values()->all();
        $next = $response->json('nextPageToken');
        $nextCursor = filled($next)
            ? ['mode' => 'initial', 'history_id' => $historyId, 'page_token' => (string) $next]
            : ['mode' => 'history', 'history_id' => $historyId];

        return [
            'messages' => $this->fetchMessages($account, $ids),
            'cursor' => $this->encodeCursor($nextCursor),
            'has_more' => filled($next),
        ];
    }

    /** @param array<string, mixed> $cursor
     * @return array{messages: array<int, array<string, mixed>>, cursor: ?string, has_more: bool}
     */
    private function historySync(EmailAccount $account, int $limit, array $cursor): array
    {
        $params = [
            'startHistoryId' => (string) $cursor['history_id'],
            'historyTypes' => 'messageAdded',
            'maxResults' => $limit,
        ];
        if (filled($cursor['page_token'] ?? null)) {
            $params['pageToken'] = (string) $cursor['page_token'];
        }
        $response = $this->request($account)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/history', $params);
        if ($response->status() === 404) {
            return $this->initialSync($account, $limit, []);
        }
        $this->assertSuccessful($response, 'history');

        $ids = collect((array) $response->json('history', []))
            ->flatMap(fn (array $entry): array => (array) ($entry['messagesAdded'] ?? []))
            ->map(fn (array $entry) => data_get($entry, 'message.id'))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()->take($limit)->values()->all();
        $next = $response->json('nextPageToken');
        $nextCursor = filled($next)
            ? [
                'mode' => 'history',
                'history_id' => (string) $cursor['history_id'],
                'page_token' => (string) $next,
            ]
            : [
                'mode' => 'history',
                'history_id' => (string) ($response->json('historyId') ?: $cursor['history_id']),
            ];

        return [
            'messages' => $this->fetchMessages($account, $ids),
            'cursor' => $this->encodeCursor($nextCursor),
            'has_more' => filled($next),
        ];
    }

    /** @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchMessages(EmailAccount $account, array $ids): array
    {
        $messages = [];
        foreach ($ids as $id) {
            $response = $this->request($account)->get(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($id),
                ['format' => 'full'],
            );
            $this->assertSuccessful($response, 'message');
            $normalized = $this->normalizeMessage($account, (array) $response->json());
            if ($normalized !== null) {
                $messages[] = $normalized;
            }
        }

        return $messages;
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
        $headers = [];
        foreach ((array) data_get($providerMessage, 'payload.headers', []) as $header) {
            if (is_array($header) && is_string($header['name'] ?? null)) {
                $headers[strtolower($header['name'])] = (string) ($header['value'] ?? '');
            }
        }
        [$fromEmail, $fromName] = $this->address($headers['from'] ?? '');
        $recipients = $this->emailAddresses($headers['to'] ?? '');
        $text = '';
        $html = '';
        $attachments = [];
        $this->walkPart(
            $account,
            $externalId,
            (array) ($providerMessage['payload'] ?? []),
            $text,
            $html,
            $attachments,
        );
        $labels = array_values(array_filter((array) ($providerMessage['labelIds'] ?? []), 'is_string'));
        $direction = in_array('SENT', $labels, true)
            || mb_strtolower($fromEmail) === mb_strtolower($account->email_address)
            ? 'outbound'
            : 'inbound';
        $internalDate = (int) ($providerMessage['internalDate'] ?? 0);

        return [
            'external_id' => $externalId,
            'thread_id' => (string) ($providerMessage['threadId'] ?? $externalId),
            'internet_message_id' => $headers['message-id'] ?? null,
            'in_reply_to' => $headers['in-reply-to'] ?? null,
            'direction' => $direction,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to' => $recipients,
            'subject' => $headers['subject'] ?? null,
            'text' => $text !== '' ? $text : (string) ($providerMessage['snippet'] ?? ''),
            'html' => $html !== '' ? $html : null,
            'occurred_at' => $internalDate > 0
                ? CarbonImmutable::createFromTimestampUTC((int) floor($internalDate / 1000))->toISOString()
                : now()->toISOString(),
            'attachments' => $attachments,
            'metadata' => [
                'provider' => 'google',
                'labels' => $labels,
                'history_id' => $providerMessage['historyId'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $part
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function walkPart(
        EmailAccount $account,
        string $messageId,
        array $part,
        string &$text,
        string &$html,
        array &$attachments,
    ): void {
        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (is_array($child)) {
                $this->walkPart($account, $messageId, $child, $text, $html, $attachments);
            }
        }

        $mimeType = strtolower((string) ($part['mimeType'] ?? 'application/octet-stream'));
        $filename = trim(basename(str_replace('\\', '/', (string) ($part['filename'] ?? ''))));
        $body = (array) ($part['body'] ?? []);
        $data = $this->decodeBase64Url((string) ($body['data'] ?? ''));
        if ($filename === '' && $mimeType === 'text/plain' && $data !== '') {
            $text .= $data;
        } elseif ($filename === '' && $mimeType === 'text/html' && $data !== '') {
            $html .= $data;
        }
        if ($filename === '') {
            return;
        }

        $size = max(0, (int) ($body['size'] ?? strlen($data)));
        $attachmentId = is_string($body['attachmentId'] ?? null) ? $body['attachmentId'] : null;
        $maxBytes = max(1, min(25 * 1024 * 1024, (int) data_get($account->settings, 'max_attachment_bytes', 25 * 1024 * 1024)));
        if ($data === '' && $attachmentId !== null && $size <= $maxBytes) {
            $response = $this->request($account)->get(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($messageId)
                    .'/attachments/'.rawurlencode($attachmentId),
            );
            $this->assertSuccessful($response, 'attachment');
            $data = $this->decodeBase64Url((string) $response->json('data', ''));
        }
        $attachments[] = [
            'external_id' => $attachmentId,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'content' => $data !== '' && strlen($data) <= $maxBytes ? $data : null,
        ];
    }

    private function request(EmailAccount $account): PendingRequest
    {
        $token = (string) data_get($account->integration->credentials, 'access_token');
        if ($account->integration->status !== 'active' || $token === '') {
            throw new RuntimeException('The Google email integration is not connected.');
        }

        return Http::acceptJson()->withToken($token)->timeout(30)->connectTimeout(10);
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("Gmail {$operation} failed with HTTP {$response->status()}.");
        }
    }

    /** @return array{0: string, 1: string} */
    private function address(string $value): array
    {
        try {
            $address = Address::create($value);

            return [mb_strtolower($address->getAddress()), $address->getName()];
        } catch (Throwable) {
            return ['', ''];
        }
    }

    /** @return array<int, string> */
    private function emailAddresses(string $value): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);

        return array_values(array_unique(array_map('mb_strtolower', $matches[0] ?? [])));
    }

    private function decodeBase64Url(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeCursor(?string $cursor): array
    {
        if (blank($cursor)) {
            return [];
        }
        $decoded = json_decode((string) $cursor, true);

        return is_array($decoded) ? $decoded : ['mode' => 'history', 'history_id' => (string) $cursor];
    }

    /** @param array<string, mixed> $cursor */
    private function encodeCursor(array $cursor): string
    {
        return json_encode($cursor, JSON_THROW_ON_ERROR);
    }
}
