<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicKnowledgeArticleResource;
use App\Http\Resources\PublicKnowledgeArticleSummaryResource;
use App\Models\KnowledgeArticle;
use App\Services\PublicKnowledgeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicKnowledgeController extends Controller
{
    public function __construct(private readonly PublicKnowledgeService $knowledge) {}

    public function home(string $basePublicId): JsonResponse
    {
        $base = $this->knowledge->base($basePublicId);

        return ApiResponse::success($this->knowledge->home($base));
    }

    public function categories(string $basePublicId): JsonResponse
    {
        $base = $this->knowledge->base($basePublicId);

        return ApiResponse::success($this->knowledge->categories($base)->map(fn ($category) => ['name' => $category->name, 'description' => $category->description, 'position' => $category->position])->values()->all());
    }

    public function articles(Request $request, string $basePublicId): JsonResponse
    {
        $filters = $request->validate(['q' => ['sometimes', 'string', 'max:120'], 'category_id' => ['sometimes', 'integer', 'min:1'], 'tag_id' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'], 'sort' => ['sometimes', Rule::in(['title', 'published_at'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])]]);
        $page = $this->knowledge->articles($this->knowledge->base($basePublicId), $filters);
        $page->through(fn (KnowledgeArticle $article) => (new PublicKnowledgeArticleSummaryResource($article))->resolve($request));

        return ApiResponse::paginated($page);
    }

    public function article(Request $request, string $basePublicId, string $articlePublicId): JsonResponse
    {
        $article = $this->knowledge->article($this->knowledge->base($basePublicId), $articlePublicId);

        return ApiResponse::success((new PublicKnowledgeArticleResource($article))->resolve($request));
    }
}
