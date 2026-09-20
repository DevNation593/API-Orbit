<?php

namespace App\Http\Resources;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicKnowledgeArticleSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->summary($this->resource);
    }

    protected function summary(KnowledgeArticle $article): array
    {
        $version = $this->publishedVersion($article);
        $category = $version->getRelation('category');

        return ['public_id' => $article->public_id, 'title' => $version->title, 'summary' => $version->summary,
            'category' => $category === null ? null : ['name' => $category->name, 'description' => $category->description, 'position' => $category->position],
            'tags' => $version->getRelation('tags')->map(fn ($tag) => ['name' => $tag->name, 'description' => $tag->description])->values()->all(),
            'seo_title' => $version->seo_title, 'seo_description' => $version->seo_description, 'version' => $version->version, 'published_at' => $article->published_at];
    }

    protected function publishedVersion(KnowledgeArticle $article): KnowledgeArticleVersion
    {
        $version = $article->getRelation('publishedVersion');
        if (! $version instanceof KnowledgeArticleVersion) {
            abort(404);
        }

        return $version;
    }
}
