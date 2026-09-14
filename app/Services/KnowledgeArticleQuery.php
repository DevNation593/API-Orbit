<?php

namespace App\Services;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class KnowledgeArticleQuery
{
    private const SORTS = [
        'created_at' => 'knowledge_articles.created_at',
        'updated_at' => 'knowledge_articles.updated_at',
        'published_at' => 'knowledge_articles.published_at',
        'title' => 'current_version.title',
    ];

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
        if (array_key_exists('has_unpublished_changes', $filters)) {
            if ((bool) $filters['has_unpublished_changes']) {
                $query->where(fn (Builder $article) => $article
                    ->whereNull('knowledge_articles.published_version_id')
                    ->orWhereColumn(
                        'knowledge_articles.current_version_id',
                        '<>',
                        'knowledge_articles.published_version_id',
                    ));
            } else {
                $query->whereNotNull('knowledge_articles.published_version_id')
                    ->whereColumn(
                        'knowledge_articles.current_version_id',
                        'knowledge_articles.published_version_id',
                    );
            }
        }
        if (isset($filters['created_from'])) {
            $query->whereDate('knowledge_articles.created_at', '>=', $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $query->whereDate('knowledge_articles.created_at', '<=', $filters['created_to']);
        }

        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? 'desc';
        if ($sort === 'title') {
            $query->join(
                'knowledge_article_versions as current_version',
                fn (JoinClause $join) => $join
                    ->on('knowledge_articles.current_version_id', '=', 'current_version.id')
                    ->on('knowledge_articles.tenant_id', '=', 'current_version.tenant_id'),
            )->select('knowledge_articles.*');
        }

        return $query->orderBy(self::SORTS[$sort], $direction)
            ->orderBy('knowledge_articles.id', $direction);
    }
}
