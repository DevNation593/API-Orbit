<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\EmailTrackingLink;
use App\Models\Message;
use Illuminate\Support\Str;

final class EmailTrackingService
{
    public function prepare(EmailAccount $account, Message $message): Message
    {
        if ((bool) data_get($message->metadata, 'tracking_prepared')) {
            return $message;
        }
        $trackOpens = (bool) data_get($account->settings, 'track_opens', false);
        $trackClicks = (bool) data_get($account->settings, 'track_clicks', false);
        if (! $trackOpens && ! $trackClicks) {
            return $message;
        }

        $content = $message->content ?? [];
        $html = is_string($content['html'] ?? null)
            ? $content['html']
            : nl2br(e((string) $message->body));
        if ($trackClicks) {
            $html = preg_replace_callback(
                "/href=(['\"])(https?:\/\/[^'\"\\s>]+)\\1/i",
                function (array $match) use ($message): string {
                    $token = Str::random(64);
                    EmailTrackingLink::create([
                        'message_id' => $message->id,
                        'token_hash' => hash('sha256', $token),
                        'destination_url' => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5),
                        'kind' => 'click',
                        'expires_at' => now()->addDays(90),
                    ]);

                    return 'href='.$match[1].url('/api/v1/track/email/click/'.$token).$match[1];
                },
                $html,
            ) ?? $html;
        }
        if ($trackOpens) {
            $token = Str::random(64);
            EmailTrackingLink::create([
                'message_id' => $message->id,
                'token_hash' => hash('sha256', $token),
                'kind' => 'open',
                'expires_at' => now()->addDays(90),
            ]);
            $html .= '<img src="'.e(url('/api/v1/track/email/open/'.$token.'.gif')).'" width="1" height="1" alt="" style="display:none">';
        }

        $content['html'] = $html;
        $message->update([
            'content' => $content,
            'metadata' => array_replace($message->metadata ?? [], ['tracking_prepared' => true]),
        ]);

        return $message->fresh(['conversation.contact', 'attachments.file', 'replyTo']);
    }
}
