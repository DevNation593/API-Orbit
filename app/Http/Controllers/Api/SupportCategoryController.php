<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportCategoryRequest;
use App\Models\TicketCategory;
use App\Services\SupportConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupportCategoryController extends Controller
{
    public function __construct(private readonly SupportConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('support.view'), 403);
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = TicketCategory::query();
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', $data['is_active']);
        }

        return ApiResponse::paginated($query->orderBy('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(SupportCategoryRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('support.manage'), 403);

        return ApiResponse::success($this->configuration->saveCategory($request->validated()), [], 201);
    }

    public function show(int $category): JsonResponse
    {
        $model = TicketCategory::findOrFail($category);
        Gate::authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(SupportCategoryRequest $request, int $category): JsonResponse
    {
        $model = TicketCategory::findOrFail($category);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveCategory($request->validated(), $model));
    }

    public function destroy(int $category): JsonResponse
    {
        $model = TicketCategory::findOrFail($category);
        Gate::authorize('delete', $model);
        $this->configuration->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
