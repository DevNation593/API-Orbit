<?php

namespace App\Services;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeCategory;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class PublicKnowledgeService
{
    public function __construct(private readonly TenantContext $context) {}

    public function base(string $publicId): KnowledgeBase
    {
        return KnowledgeBase::withoutGlobalScope('tenant')->where('public_id', $publicId)->where('is_public', true)->firstOrFail();
    }

    public function home(KnowledgeBase $base): array
    {
        return $this->withinTenant($base, fn (): array => $base->only(['public_id', 'title', 'description']));
    }

    public function categories(KnowledgeBase $base): Collection
    {
        return $this->withinTenant($base, fn (): Collection => KnowledgeCategory::query()
            ->whereHas('versions', fn (Builder $version) => $version->where('visibility', 'PUBLIC')->whereHas('article', fn (Builder $article) => $article->where('status', 'PUBLISHED')->whereColumn('knowledge_articles.published_version_id', 'knowledge_article_versions.id')))
            ->orderBy('position')->orderBy('name')->get());
    }

    public function articles(KnowledgeBase $base, array $filters): LengthAwarePaginator
    {
        return $this->withinTenant($base, function () use ($base, $filters): LengthAwarePaginator {
            $query = KnowledgeArticle::query()->where('status', 'PUBLISHED')
                ->whereHas('publishedVersion', fn (Builder $version) => $version->where('visibility', 'PUBLIC'))
                ->with(['publishedVersion.category', 'publishedVersion.tags']);
            if (filled($filters['q'] ?? null)) {
                $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim((string) $filters['q'])));
                $query->whereHas('publishedVersion', fn (Builder $version) => $version->where(fn (Builder $text) => $text->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", ['%'.$search.'%'])->orWhereRaw("LOWER(summary) LIKE ? ESCAPE '!'", ['%'.$search.'%'])));
            }
            if (isset($filters['category_id'])) {
                $query->whereHas('publishedVersion', fn (Builder $version) => $version->where('category_id', $filters['category_id']));
            }
            if (isset($filters['tag_id'])) {
                $query->whereHas('publishedVersion.tags', fn (Builder $tag) => $tag->whereKey($filters['tag_id']));
            }
            $direction = $filters['direction'] ?? 'desc';
            if (($filters['sort'] ?? 'published_at') === 'title') {
                $query->join('knowledge_article_versions as public_versions', fn (JoinClause $join) => $join->on('knowledge_articles.published_version_id', '=', 'public_versions.id'))
                    ->where('public_versions.tenant_id', $base->tenant_id)->select('knowledge_articles.*')->orderBy('public_versions.title', $direction);
            } else {
                $query->orderBy('knowledge_articles.published_at', $direction);
            }

            return $query->orderBy('knowledge_articles.id', $direction)->paginate(ApiResponse::perPage($filters['per_page'] ?? 25))->withQueryString();
        });
    }

    public function article(KnowledgeBase $base, string $articlePublicId): KnowledgeArticle
    {
        return $this->withinTenant($base, fn (): KnowledgeArticle => KnowledgeArticle::query()->where('public_id', $articlePublicId)->where('status', 'PUBLISHED')->whereHas('publishedVersion', fn (Builder $version) => $version->where('visibility', 'PUBLIC'))->with(['publishedVersion.category', 'publishedVersion.tags'])->firstOrFail());
    }

    private function withinTenant(KnowledgeBase $base, Closure $callback): mixed
    {
        $previous = $this->context->id();
        try {
            $this->context->set((int) $base->tenant_id);

            return $callback();
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
