<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnowledgeTagRequest;
use App\Models\KnowledgeTag;
use App\Services\KnowledgeConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KnowledgeTagController extends KnowledgeCatalogController
{
    public function __construct(private readonly KnowledgeConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KnowledgeTag::class);
        $data = $this->catalogFilters($request);
        $query = $this->applyCatalogFilters(KnowledgeTag::query(), $data);

        return ApiResponse::paginated(
            $query->orderBy('name')->orderBy('id')
                ->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString(),
        );
    }

    public function store(KnowledgeTagRequest $request): JsonResponse
    {
        Gate::authorize('create', KnowledgeTag::class);
        $tag = $this->configuration->saveTag(
            $request->validated(),
            (int) $request->user()->id,
        );

        return ApiResponse::success($tag, [], 201);
    }

    public function show(int $tag): JsonResponse
    {
        $model = KnowledgeTag::findOrFail($tag);
        Gate::authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(KnowledgeTagRequest $request, int $tag): JsonResponse
    {
        $model = KnowledgeTag::findOrFail($tag);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveTag(
            $request->validated(),
            (int) $request->user()->id,
            $model,
        ));
    }

    public function destroy(int $tag): JsonResponse
    {
        $model = KnowledgeTag::findOrFail($tag);
        Gate::authorize('delete', $model);
        $this->configuration->deleteCatalog($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
