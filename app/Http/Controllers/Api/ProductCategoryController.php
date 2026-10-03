<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductCategory::class);
        $query = ProductCategory::query()->withCount('products')->with('parent:id,name');
        if ($request->filled('parent_id')) {
            $request->validate(['parent_id' => ['integer', 'min:1']]);
            $query->where('parent_id', $request->integer('parent_id'));
        }
        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id')->with('children.children');
        }

        return ApiResponse::success($query->orderBy('position')->orderBy('name')->get());
    }

    public function store(ProductCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', ProductCategory::class);

        return ApiResponse::success($this->catalog->saveCategory(new ProductCategory, $request->validated()), [], 201);
    }

    public function show(int $category): JsonResponse
    {
        $model = ProductCategory::with(['parent:id,name', 'children:id,parent_id,name,position,active'])->withCount('products')->findOrFail($category);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(ProductCategoryRequest $request, int $category): JsonResponse
    {
        $model = ProductCategory::findOrFail($category);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveCategory($model, $request->validated()));
    }

    public function destroy(int $category): JsonResponse
    {
        $model = ProductCategory::findOrFail($category);
        $this->authorize('delete', $model);
        if ($model->children()->exists() || $model->products()->exists()) {
            throw ValidationException::withMessages(['category' => 'Move child categories and products before deleting this category.']);
        }
        $this->catalog->delete($model, 'product_category_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
