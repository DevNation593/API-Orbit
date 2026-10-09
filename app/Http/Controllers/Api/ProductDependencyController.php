<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDependencyRequest;
use App\Models\ProductDependency;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductDependencyController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ProductDependency::class);

        return ApiResponse::success(ProductDependency::query()->with(['product:id,sku,name', 'relatedProduct:id,sku,name'])->orderBy('product_id')->get());
    }

    public function store(ProductDependencyRequest $request): JsonResponse
    {
        $this->authorize('create', ProductDependency::class);

        return ApiResponse::success($this->catalog->saveDependency(new ProductDependency, $request->validated()), [], 201);
    }

    public function update(ProductDependencyRequest $request, int $dependency): JsonResponse
    {
        $model = ProductDependency::findOrFail($dependency);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveDependency($model, $request->validated()));
    }

    public function destroy(int $dependency): JsonResponse
    {
        $model = ProductDependency::findOrFail($dependency);
        $this->authorize('delete', $model);
        $this->catalog->delete($model, 'product_dependency_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
