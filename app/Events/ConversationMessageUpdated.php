<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenants.'.$this->message->tenant_id.'.inbox')];
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->message->id,
            'conversation_id' => (int) $this->message->conversation_id,
            'direction' => $this->message->direction,
            'type' => $this->message->type,
            'status' => $this->message->status,
            'occurred_at' => $this->message->occurred_at?->toISOString(),
        ];
    }
}
