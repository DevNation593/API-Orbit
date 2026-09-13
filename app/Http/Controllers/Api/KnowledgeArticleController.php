<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeArticleRequest;
use App\Http\Resources\KnowledgeArticleResource;
use App\Http\Resources\KnowledgeArticleSummaryResource;
use App\Models\KnowledgeArticle;
use App\Services\KnowledgeArticleQuery;
use App\Services\KnowledgeArticleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class KnowledgeArticleController extends Controller
{
    public function __construct(
        private readonly KnowledgeArticleService $articles,
        private readonly KnowledgeArticleQuery $query,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KnowledgeArticle::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],
            'visibility' => ['sometimes', Rule::in(['PUBLIC', 'CUSTOMER', 'INTERNAL'])],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'tag_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $page = $this->query->internal($filters)
            ->paginate(ApiResponse::perPage($filters['per_page'] ?? 25))
            ->withQueryString();
        $page->through(
            fn (KnowledgeArticle $article): array => (new KnowledgeArticleSummaryResource($article))
                ->resolve($request)
        );

        return ApiResponse::paginated($page);
    }

    public function store(KnowledgeArticleRequest $request): JsonResponse
    {
        Gate::authorize('create', KnowledgeArticle::class);
        $article = $this->articles->create($request->validated(), $request->user());

        return ApiResponse::success(
            (new KnowledgeArticleResource($article))->resolve($request),
            [],
            201,
        );
    }

    public function show(Request $request, int $article): JsonResponse
    {
        $model = $this->query->internal([])->findOrFail($article);
        Gate::authorize('view', $model);

        return ApiResponse::success(
            (new KnowledgeArticleResource($model))->resolve($request),
        );
    }

    public function update(KnowledgeArticleRequest $request, int $article): JsonResponse
    {
        $model = KnowledgeArticle::findOrFail($article);
        Gate::authorize('update', $model);
        $article = $this->articles->revise($model, $request->validated(), $request->user());

        return ApiResponse::success(
            (new KnowledgeArticleResource($article))->resolve($request),
        );
    }
}
