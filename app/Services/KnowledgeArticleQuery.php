<?php

namespace App\Services;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeArticleQuery
{
    public function internal(array $filters): Builder
    {
        $query = KnowledgeArticle::query()->with([
            'currentVersion.category',
            'currentVersion.tags',
            'publishedVersion.category',
            'publishedVersion.tags',
        ]);

        if (filled($filters['q'] ?? null)) {
            $search = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                mb_strtolower(trim((string) $filters['q'])),
            );
            $query->whereHas('currentVersion', function (Builder $version) use ($search): void {
                $version->where(function (Builder $text) use ($search): void {
                    $text->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", ['%'.$search.'%'])
                        ->orWhereRaw("LOWER(summary) LIKE ? ESCAPE '!'", ['%'.$search.'%']);
                });
            });
        }

        if (isset($filters['status'])) {
            $query->where('knowledge_articles.status', $filters['status']);
        }
        if (isset($filters['visibility'])) {
            $query->whereHas('currentVersion', fn (Builder $version) => $version
                ->where('visibility', $filters['visibility']));
        }
        if (isset($filters['category_id'])) {
            $query->whereHas('currentVersion', fn (Builder $version) => $version
                ->where('category_id', $filters['category_id']));
        }
        if (isset($filters['tag_id'])) {
            $query->whereHas('currentVersion.tags', fn (Builder $tag) => $tag
                ->whereKey($filters['tag_id']));
        }

        return $query->orderByDesc('knowledge_articles.updated_at')
            ->orderByDesc('knowledge_articles.id');
    }
}
