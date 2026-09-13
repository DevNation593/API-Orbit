<?php

namespace App\Policies;

use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class KnowledgeArticlePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'knowledge.view');
    }

    public function view(User $user, KnowledgeArticle $article): bool
    {
        return $this->allowed($user, 'knowledge.view', $article);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'knowledge.manage');
    }

    public function update(User $user, KnowledgeArticle $article): bool
    {
        return $this->allowed($user, 'knowledge.manage', $article);
    }

    public function publish(User $user, KnowledgeArticle $article): bool
    {
        return $this->view($user, $article)
            && $this->allowed($user, 'knowledge.publish', $article);
    }

    public function archive(User $user, KnowledgeArticle $article): bool
    {
        return $this->publish($user, $article);
    }

    public function restore(User $user, KnowledgeArticle $article): bool
    {
        return $this->publish($user, $article);
    }
}
