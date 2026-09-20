<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PublicKnowledgeArticleResource extends PublicKnowledgeArticleSummaryResource
{
    public function toArray(Request $request): array
    {
        return $this->summary($this->resource) + ['body_html' => $this->publishedVersion($this->resource)->body_html];
    }
}
