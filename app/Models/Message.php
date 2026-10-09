<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use TenantScoped;

    protected $fillable = [
        'conversation_id', 'inbox_channel_id', 'sender_user_id', 'sender_contact_id', 'reply_to_id',
        'direction', 'type', 'sender_type', 'subject', 'body', 'content', 'metadata', 'status',
        'client_message_id', 'external_message_id', 'is_internal', 'occurred_at', 'sent_at',
        'scheduled_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'metadata' => 'array',
            'is_internal' => 'boolean',
            'occurred_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function inboxChannel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_contact_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
