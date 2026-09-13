<?php

namespace App\Http\Resources;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KnowledgeArticle $article */
        $article = $this->resource;
        $current = $article->relationLoaded('currentVersion')
            ? $article->getRelation('currentVersion')
            : null;
        $published = $article->relationLoaded('publishedVersion')
            ? $article->getRelation('publishedVersion')
            : null;

        return $article->only([
            'id',
            'tenant_id',
            'public_id',
            'status',
            'current_version_id',
            'published_version_id',
            'created_by',
            'updated_by',
            'published_at',
            'archived_at',
            'created_at',
            'updated_at',
        ]) + [
            'current_version' => $current === null
                ? null
                : (new KnowledgeArticleVersionResource($current))->resolve($request),
            'published_version' => $this->versionSummary($published),
            'has_unpublished_changes' => $article->published_version_id === null
                || $article->current_version_id !== $article->published_version_id,
        ];
    }

    private function versionSummary(?KnowledgeArticleVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        return $version->only([
            'id',
            'version',
            'category_id',
            'author_id',
            'title',
            'summary',
            'visibility',
            'seo_title',
            'seo_description',
            'change_summary',
            'created_at',
            'updated_at',
        ]);
    }
}
