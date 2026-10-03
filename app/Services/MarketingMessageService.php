<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\InboxChannel;
use App\Models\Message;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class MarketingMessageService
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly ConversationMessageService $messages,
    ) {}

    public function enqueue(Model $subject, string $entityType, int $senderId, array $email, string $key, array $metadata): Message
    {
        $destination = $this->consent->destination($subject, 'email');
        if (! $this->consent->allowed($subject, 'email')) {
            throw ValidationException::withMessages(['consent' => 'An explicit current email opt-in is required.']);
        }
        abort_unless(TenantUser::where('user_id', $senderId)->where('tenant_id', $subject->tenant_id)
            ->where('status', 'active')->exists(), 409, 'The marketing sender is not an active member.');
        $channel = InboxChannel::whereKey($email['inbox_channel_id'])->where('channel', 'email')
            ->where('status', 'active')->firstOrFail();
        $subjectLine = $this->render($email['subject'], $subject);
        $conversation = Conversation::firstOrCreate([
            'inbox_channel_id' => $channel->id, 'external_identifier' => $key,
        ], [
            'inbox_id' => $channel->inbox_id, 'channel' => 'email',
            'contact_id' => $entityType === 'contacts' ? $subject->id : null,
            'assigned_user_id' => $senderId, 'subject' => $subjectLine, 'status' => 'open', 'priority' => 'normal',
        ]);
        $conversation->participants()->firstOrCreate([
            'type' => 'external', 'external_identifier' => $destination,
        ], ['role' => 'customer', 'display_name' => trim($subject->first_name.' '.$subject->last_name)]);
        // The link is generated inside the caller transaction; raw bearer tokens never enter the audit ledger.
        $link = $this->consent->issueLink($entityType, (int) $subject->id);
        $body = $this->render($email['body'], $subject)."\n\nPreferencias y baja: ".$link['url'];
        $html = filled($email['body_html'] ?? null)
            ? $this->render($email['body_html'], $subject, true).'<p><a href="'.e($link['url']).'">Administrar preferencias / Darme de baja</a></p>'
            : null;

        return $this->messages->send($conversation, User::findOrFail($senderId), [
            'type' => 'email', 'subject' => $subjectLine, 'body' => $body,
            'content' => array_filter(['to' => $destination, 'html' => $html], fn ($value) => $value !== null),
            'client_message_id' => $key,
        ], ['marketing' => $metadata + [
            'entity_type' => $entityType, 'entity_id' => (int) $subject->id,
            'channel' => 'email', 'destination' => $destination,
        ]])['message'];
    }

    private function render(string $template, Model $subject, bool $html = false): string
    {
        return preg_replace_callback('/{{\\s*(?:contact|lead)\\.(first_name|last_name|email)\\s*}}/', function ($matches) use ($subject, $html): string {
            $value = (string) $subject->getAttribute($matches[1]);

            return $html ? e($value) : $value;
        }, $template) ?? $template;
    }
}
