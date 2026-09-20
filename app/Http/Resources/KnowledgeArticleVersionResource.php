<?php

namespace App\Http\Resources;

use App\Models\KnowledgeArticleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeArticleVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KnowledgeArticleVersion $version */
        $version = $this->resource;

        return $version->only([
            'id',
            'tenant_id',
            'article_id',
            'version',
            'category_id',
            'author_id',
            'title',
            'summary',
            'body_html',
            'visibility',
            'seo_title',
            'seo_description',
            'change_summary',
            'created_at',
            'updated_at',
        ]) + [
            'category' => $version->relationLoaded('category')
                ? $version->getRelation('category')?->only([
                    'id', 'name', 'description', 'position', 'is_active',
                ])
                : null,
            'tags' => $version->relationLoaded('tags')
                ? $version->getRelation('tags')->map->only([
                    'id', 'name', 'description', 'is_active',
                ])->values()->all()
                : [],
        ];
    }
}
