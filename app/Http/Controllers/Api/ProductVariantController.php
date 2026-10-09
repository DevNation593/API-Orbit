<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ProductVariantController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function store(ProductVariantRequest $request, int $product): JsonResponse
    {
        $parent = Product::findOrFail($product);
        $this->authorize('update', $parent);

        return ApiResponse::success($this->catalog->saveVariant($parent, new ProductVariant, $request->validated()), [], 201);
    }

    public function update(ProductVariantRequest $request, int $product, int $variant): JsonResponse
    {
        $parent = Product::findOrFail($product);
        $this->authorize('update', $parent);
        $model = ProductVariant::findOrFail($variant);

        return ApiResponse::success($this->catalog->saveVariant($parent, $model, $request->validated()));
    }

    public function destroy(int $product, int $variant): JsonResponse
    {
        $parent = Product::findOrFail($product);
        $this->authorize('update', $parent);
        $model = ProductVariant::query()->where('product_id', $parent->id)->findOrFail($variant);
        if ($model->bundleItems()->whereHas('bundle')->exists()) {
            throw ValidationException::withMessages([
                'variant' => 'Remove this variant from bundles before deleting it.',
            ]);
        }
        $this->catalog->delete($model, 'product_variant_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
