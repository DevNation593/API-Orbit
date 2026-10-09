<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ConversationPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'conversations.view');
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->allowed($user, 'conversations.view', $conversation);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'conversations.reply');
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->allowed($user, 'conversations.reply', $conversation);
    }

    public function assign(User $user, Conversation $conversation): bool
    {
        return $this->allowed($user, 'conversations.assign', $conversation);
    }

    public function changeStatus(User $user, Conversation $conversation): bool
    {
        return $this->allowed($user, 'conversations.reply', $conversation);
    }
}
