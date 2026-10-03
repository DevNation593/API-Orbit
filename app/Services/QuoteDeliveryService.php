<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\EmailAccount;
use App\Models\Quote;
use App\Models\User;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QuoteDeliveryService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly QuotePdfService $pdf,
        private readonly ConversationMessageService $messages,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{quote: Quote, acceptance_token: string, message_id: int, replayed: bool}
     */
    public function send(Quote $quote, User $sender, array $data): array
    {
        if (in_array($quote->status, ['sent', 'viewed'], true) && filled($quote->acceptance_token)) {
            return [
                'quote' => $quote->fresh(), 'acceptance_token' => (string) $quote->acceptance_token,
                'message_id' => (int) data_get($quote->metadata, 'delivery_message_id', 0), 'replayed' => true,
            ];
        }
        if ($quote->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only approved quotes can be sent.']);
        }
        $quote->loadMissing(['contact', 'organization']);
        $recipient = (string) ($data['recipient_email'] ?? $quote->contact?->email ?? $quote->organization?->email ?? '');
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient_email' => 'A valid quote recipient email is required.']);
        }
        $account = $this->emailAccount($quote);
        $channel = $account->channel;
        $conversation = Conversation::query()->where('inbox_channel_id', $channel->id)
            ->where(function ($query) use ($quote, $recipient): void {
                if ($quote->contact_id !== null) {
                    $query->where('contact_id', $quote->contact_id);
                } else {
                    $query->where('external_identifier', $recipient);
                }
            })->whereNotIn('status', ['closed'])->latest('id')->first();
        if ($conversation === null) {
            $conversation = Conversation::create([
                'inbox_id' => $channel->inbox_id, 'inbox_channel_id' => $channel->id,
                'contact_id' => $quote->contact_id, 'assigned_user_id' => $quote->owner_id,
                'channel' => 'email', 'subject' => 'Cotización '.$quote->number,
                'external_identifier' => $recipient, 'status' => 'open', 'priority' => 'normal',
            ]);
            $conversation->participants()->create([
                'contact_id' => $quote->contact_id, 'type' => $quote->contact_id === null ? 'external' : 'contact',
                'role' => 'customer', 'external_identifier' => $recipient,
            ]);
        }
        $file = $quote->pdfFile ?? $this->pdf->generate($quote);
        $token = Str::random(64);
        $url = url('/api/v1/public/quotes/'.$quote->public_id).'?token='.rawurlencode($token);
        $subject = $data['subject'] ?? 'Cotización '.$quote->number.' de '.$quote->tenant()->value('name');
        $body = $data['message'] ?? 'Adjuntamos tu cotización. Puedes revisarla y responderla mediante el enlace seguro.';
        $message = $this->messages->send($conversation, $sender, [
            'type' => 'email', 'subject' => $subject, 'body' => $body."\n\n".$url,
            'content' => ['to' => $recipient, 'html' => '<p>'.e($body).'</p><p><a href="'.e($url).'">Revisar cotización</a></p>'],
            'file_ids' => [$file->id], 'client_message_id' => 'quote:'.$quote->id.':v'.$quote->version,
        ]);

        $model = $this->database->transaction(function () use ($quote, $token, $message, $recipient): Quote {
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($model->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'The quote changed state while it was being sent.']);
            }
            $old = ['status' => $model->status];
            $model->update([
                'status' => 'sent', 'sent_at' => now(), 'acceptance_token' => $token,
                'acceptance_token_hash' => hash('sha256', $token),
                'metadata' => array_replace($model->metadata ?? [], [
                    'delivery_message_id' => $message['message']->id, 'recipient_email' => $recipient,
                ]),
            ]);
            $model->activities()->create([
                'user_id' => auth()->id(), 'type' => 'quote.sent',
                'metadata' => ['message_id' => $message['message']->id, 'recipient_email' => $recipient], 'occurred_at' => now(),
            ]);
            $this->audit->record('quote_sent', $model, oldValues: $old, newValues: ['status' => 'sent', 'message_id' => $message['message']->id]);

            return $model;
        });

        return ['quote' => $model->fresh(), 'acceptance_token' => $token, 'message_id' => (int) $message['message']->id, 'replayed' => false];
    }

    private function emailAccount(Quote $quote): EmailAccount
    {
        $query = EmailAccount::query()->with(['channel.inbox', 'integration'])
            ->where('status', 'active')->whereHas('channel', fn ($part) => $part->where('status', 'active')->where('channel', 'email'))
            ->whereHas('integration', fn ($part) => $part->where('status', 'active'));
        $account = $quote->owner_id === null ? null : (clone $query)->where('user_id', $quote->owner_id)->first();
        $account ??= $query->orderBy('id')->first();
        if ($account === null || $account->channel === null) {
            throw ValidationException::withMessages(['email_account' => 'Configure an active tenant email account before sending quotes.']);
        }

        return $account;
    }
}
