<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailMimeBuilder
{
    public function build(EmailAccount $account, Message $message, string $recipient): Email
    {
        $email = (new Email)
            ->from(new Address($account->email_address, $account->display_name ?? ''))
            ->to($recipient)
            ->subject($this->header($message->subject ?: '(Sin asunto)'));

        $text = (string) ($message->body ?? '');
        $html = is_string(data_get($message->content, 'html')) ? data_get($message->content, 'html') : null;
        if ($account->signature_html !== null) {
            $html = ($html ?? nl2br(e($text))).$account->signature_html;
        }
        if ($text !== '') {
            $email->text($text);
        }
        if (is_string($html) && $html !== '') {
            $email->html($html);
        }
        if ($text === '' && blank($html)) {
            $email->text(' ');
        }

        foreach ($message->attachments as $attachment) {
            $file = $attachment->file;
            if ($file === null) {
                continue;
            }
            $email->attach(
                Storage::disk($file->disk)->get($file->path),
                $attachment->filename,
                $attachment->mime_type,
            );
        }

        return $email;
    }

    private function header(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
