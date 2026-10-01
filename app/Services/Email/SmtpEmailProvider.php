<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\Message;
use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Throwable;

final class SmtpEmailProvider implements EmailProviderInterface
{
    public function __construct(private readonly EmailMimeBuilder $mime) {}

    public function supports(string $provider): bool
    {
        return $provider === 'smtp';
    }

    public function send(EmailAccount $account, Message $message, string $recipient): array
    {
        $credentials = $account->integration->credentials ?? [];
        if ($account->integration->status !== 'active') {
            throw new RuntimeException('The SMTP integration is not connected.');
        }
        $host = (string) ($credentials['host'] ?? '');
        $port = (int) ($credentials['port'] ?? 0);
        if ($host === '' || $port < 1) {
            throw new RuntimeException('The SMTP integration is incomplete.');
        }
        $scheme = ($credentials['encryption'] ?? null) === 'ssl' ? 'smtps' : 'smtp';
        $user = rawurlencode((string) ($credentials['username'] ?? ''));
        $password = rawurlencode((string) ($credentials['password'] ?? ''));
        $authentication = $user !== '' ? $user.':'.$password.'@' : '';
        $transport = Transport::fromDsn("{$scheme}://{$authentication}{$host}:{$port}");
        (new Mailer($transport))->send($this->mime->build($account, $message, $recipient));

        return ['status' => 'sent', 'external_message_id' => null, 'metadata' => ['provider' => 'smtp']];
    }

    public function sync(EmailAccount $account, int $limit = 100): array
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('Inbound IMAP synchronization requires the PHP ext-imap extension.');
        }
        $credentials = $account->integration->credentials ?? [];
        $host = (string) ($credentials['imap_host'] ?? '');
        $port = (int) ($credentials['imap_port'] ?? 993);
        $username = (string) ($credentials['imap_username'] ?? $credentials['username'] ?? '');
        $password = (string) ($credentials['imap_password'] ?? $credentials['password'] ?? '');
        if ($account->integration->status !== 'active' || $host === '' || $username === '') {
            throw new RuntimeException('The IMAP integration is incomplete or disconnected.');
        }
        $encryption = (string) ($credentials['imap_encryption'] ?? 'ssl');
        $folder = (string) ($credentials['imap_folder'] ?? 'INBOX');
        $flags = '/imap'.match ($encryption) {
            'ssl' => '/ssl',
            'tls' => '/tls',
            default => '',
        };
        $mailboxName = '{'.$host.':'.$port.$flags.'}'.$folder;
        $mailbox = @imap_open($mailboxName, $username, $password, OP_READONLY, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);
        if ($mailbox === false) {
            throw new RuntimeException('The IMAP mailbox could not be opened.');
        }

        try {
            $limit = max(1, min(100, $limit));
            $lastUid = max(0, (int) $account->sync_cursor);
            $uids = imap_search($mailbox, $lastUid > 0 ? 'UID '.($lastUid + 1).':*' : 'ALL', SE_UID) ?: [];
            $uids = array_values(array_unique(array_map('intval', $uids)));
            if ($lastUid === 0) {
                rsort($uids, SORT_NUMERIC);
                $uids = array_slice($uids, 0, $limit + 1);
            }
            sort($uids, SORT_NUMERIC);
            $hasMore = count($uids) > $limit;
            $uids = array_slice($uids, 0, $limit);
            $messages = [];
            foreach ($uids as $uid) {
                $normalized = $this->normalizeImapMessage($account, $mailbox, $uid);
                if ($normalized !== null) {
                    $messages[] = $normalized;
                }
            }

            return [
                'messages' => $messages,
                'cursor' => $uids === [] ? ($account->sync_cursor ?: null) : (string) max($uids),
                'has_more' => $hasMore,
            ];
        } finally {
            imap_close($mailbox);
        }
    }

    /** @return array<string, mixed>|null */
    private function normalizeImapMessage(EmailAccount $account, mixed $mailbox, int $uid): ?array
    {
        $overview = imap_fetch_overview($mailbox, (string) $uid, FT_UID)[0] ?? null;
        if (! is_object($overview)) {
            return null;
        }
        [$fromEmail, $fromName] = $this->address($this->decodeHeader((string) ($overview->from ?? '')));
        $toHeader = $this->decodeHeader((string) ($overview->to ?? ''));
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $toHeader, $matches);
        $text = '';
        $html = '';
        $attachments = [];
        $structure = imap_fetchstructure($mailbox, $uid, FT_UID);
        if (is_object($structure)) {
            $this->walkImapPart($account, $mailbox, $uid, $structure, '1', $text, $html, $attachments);
        }
        if ($text === '' && $html === '') {
            $text = (string) imap_body($mailbox, $uid, FT_UID | FT_PEEK);
        }
        $messageId = trim((string) ($overview->message_id ?? ''), '<> ');
        $references = trim((string) ($overview->references ?? ''), '<> ');
        $inReplyTo = trim((string) ($overview->in_reply_to ?? ''), '<> ');
        $timestamp = isset($overview->udate) ? (int) $overview->udate : time();

        return [
            'external_id' => 'imap-'.$uid,
            'thread_id' => $inReplyTo ?: ($references ?: ($messageId ?: 'imap-'.$uid)),
            'internet_message_id' => $messageId !== '' ? $messageId : null,
            'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
            'direction' => mb_strtolower($fromEmail) === mb_strtolower($account->email_address) ? 'outbound' : 'inbound',
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to' => array_values(array_unique(array_map('mb_strtolower', $matches[0] ?? []))),
            'subject' => $this->decodeHeader((string) ($overview->subject ?? '')),
            'text' => $text,
            'html' => $html !== '' ? $html : null,
            'occurred_at' => CarbonImmutable::createFromTimestampUTC($timestamp)->toISOString(),
            'attachments' => $attachments,
            'metadata' => ['provider' => 'imap', 'uid' => $uid],
        ];
    }

    /** @param array<int, array<string, mixed>> $attachments */
    private function walkImapPart(
        EmailAccount $account,
        mixed $mailbox,
        int $uid,
        object $part,
        string $partNumber,
        string &$text,
        string &$html,
        array &$attachments,
    ): void {
        if (! empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $number = $partNumber === '1' ? (string) ($index + 1) : $partNumber.'.'.($index + 1);
                $this->walkImapPart($account, $mailbox, $uid, $child, $number, $text, $html, $attachments);
            }

            return;
        }
        $data = (string) imap_fetchbody($mailbox, $uid, $partNumber, FT_UID | FT_PEEK);
        $data = match ((int) ($part->encoding ?? 0)) {
            3 => base64_decode($data, true) ?: '',
            4 => quoted_printable_decode($data),
            default => $data,
        };
        $parameters = [];
        foreach (array_merge((array) ($part->parameters ?? []), (array) ($part->dparameters ?? [])) as $parameter) {
            if (is_object($parameter) && isset($parameter->attribute, $parameter->value)) {
                $parameters[strtolower((string) $parameter->attribute)] = (string) $parameter->value;
            }
        }
        $charset = $parameters['charset'] ?? null;
        if (is_string($charset) && $charset !== '' && mb_strtoupper($charset) !== 'UTF-8') {
            $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
            $data = is_string($converted) ? $converted : $data;
        }
        $filename = trim(basename(str_replace('\\', '/', $parameters['filename'] ?? $parameters['name'] ?? '')));
        $primaryTypes = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $mimeType = ($primaryTypes[(int) ($part->type ?? 7)] ?? 'application')
            .'/'.strtolower((string) ($part->subtype ?? 'octet-stream'));
        if ($filename === '' && $mimeType === 'text/plain') {
            $text .= $data;

            return;
        }
        if ($filename === '' && $mimeType === 'text/html') {
            $html .= $data;

            return;
        }
        if ($filename === '') {
            return;
        }
        $maxBytes = max(1, min(25 * 1024 * 1024, (int) data_get($account->settings, 'max_attachment_bytes', 25 * 1024 * 1024)));
        $attachments[] = [
            'external_id' => 'imap-'.$uid.'-'.$partNumber,
            'filename' => $this->decodeHeader($filename),
            'mime_type' => $mimeType,
            'size' => strlen($data),
            'content' => strlen($data) <= $maxBytes ? $data : null,
        ];
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

    private function decodeHeader(string $value): string
    {
        if ($value === '' || ! function_exists('imap_mime_header_decode')) {
            return $value;
        }
        $decoded = '';
        foreach (imap_mime_header_decode($value) as $part) {
            $text = (string) ($part->text ?? '');
            $charset = (string) ($part->charset ?? 'default');
            if (! in_array(mb_strtolower($charset), ['default', 'utf-8', 'us-ascii'], true)) {
                $text = @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            }
            $decoded .= $text;
        }

        return $decoded;
    }
}
