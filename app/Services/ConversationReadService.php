<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ConversationReadService
{
    public function applyUnreadFilter(Builder $query, User $user, bool $unread): void
    {
        $method = $unread ? 'whereExists' : 'whereNotExists';
        $query->{$method}(function ($messages) use ($user): void {
            $messages->selectRaw('1')->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.tenant_id', 'conversations.tenant_id')
                ->where('messages.direction', 'inbound')
                ->whereRaw(
                    'messages.id > COALESCE((SELECT conversation_reads.last_read_message_id FROM conversation_reads WHERE conversation_reads.conversation_id = conversations.id AND conversation_reads.user_id = ? AND conversation_reads.tenant_id = conversations.tenant_id LIMIT 1), 0)',
                    [$user->id],
                );
        });
    }

    /** @param Collection<int, Conversation> $conversations */
    public function decorate(Collection $conversations, User $user): Collection
    {
        if ($conversations->isEmpty()) {
            return $conversations;
        }

        $reads = ConversationRead::query()->where('user_id', $user->id)
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->pluck('last_read_message_id', 'conversation_id');

        $conversations->each(function (Conversation $conversation) use ($reads): void {
            $lastRead = (int) ($reads[$conversation->id] ?? 0);
            $conversation->setAttribute('unread_count', Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->where('id', '>', $lastRead)
                ->count());
        });

        return $conversations;
    }

    public function markRead(Conversation $conversation, User $user): ConversationRead
    {
        $latestMessageId = $conversation->messages()->max('id');

        return ConversationRead::updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->id],
            ['last_read_message_id' => $latestMessageId, 'read_at' => now()],
        );
    }
}
