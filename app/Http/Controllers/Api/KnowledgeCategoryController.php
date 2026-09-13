<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnowledgeCategoryRequest;
use App\Models\KnowledgeCategory;
use App\Services\KnowledgeConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KnowledgeCategoryController extends KnowledgeCatalogController
{
    public function __construct(private readonly KnowledgeConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KnowledgeCategory::class);
        $data = $this->catalogFilters($request);
        $query = $this->applyCatalogFilters(KnowledgeCategory::query(), $data);

        return ApiResponse::paginated(
            $query->orderBy('position')->orderBy('name')->orderBy('id')
                ->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString(),
        );
    }

    public function store(KnowledgeCategoryRequest $request): JsonResponse
    {
        Gate::authorize('create', KnowledgeCategory::class);
        $category = $this->configuration->saveCategory(
            $request->validated(),
            (int) $request->user()->id,
        );

        return ApiResponse::success($category, [], 201);
    }

    public function show(int $category): JsonResponse
    {
        $model = KnowledgeCategory::findOrFail($category);
        Gate::authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(KnowledgeCategoryRequest $request, int $category): JsonResponse
    {
        $model = KnowledgeCategory::findOrFail($category);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveCategory(
            $request->validated(),
            (int) $request->user()->id,
            $model,
        ));
    }

    public function destroy(int $category): JsonResponse
    {
        $model = KnowledgeCategory::findOrFail($category);
        Gate::authorize('delete', $model);
        $this->configuration->deleteCatalog($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
