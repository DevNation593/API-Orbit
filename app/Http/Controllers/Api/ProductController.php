<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Services\CatalogService;
use App\Services\ErpSyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private readonly CatalogService $catalog, private readonly ErpSyncService $erp) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', 'in:product,service,subscription,bundle'],
            'category_id' => ['nullable', 'integer', 'min:1'], 'currency_id' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Product::query()->with(['category:id,name', 'currency:id,code,symbol,decimal_places'])->withCount('variants');
        if (filled($data['q'] ?? null)) {
            $escaped = addcslashes((string) $data['q'], '%_\\');
            $query->where(fn ($part) => $part->where('name', 'like', "%{$escaped}%")->orWhere('sku', 'like', "%{$escaped}%"));
        }
        foreach (['type', 'category_id', 'currency_id', 'active'] as $field) {
            if (array_key_exists($field, $data)) {
                $query->where($field, $data[$field]);
            }
        }

        return ApiResponse::paginated($query->orderBy('name')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        return ApiResponse::success($this->catalog->saveProduct(new Product, $request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $product): JsonResponse
    {
        $model = Product::with(['category:id,name', 'currency:id,code,name,symbol,decimal_places,exchange_rate', 'creator:id,name', 'taxes', 'variants', 'bundle.items.product.currency', 'bundle.items.variant', 'dependencies.relatedProduct:id,sku,name'])->findOrFail($product);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(ProductRequest $request, int $product): JsonResponse
    {
        $model = Product::findOrFail($product);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveProduct($model, $request->validated(), (int) $request->user()->id));
    }

    public function destroy(int $product): JsonResponse
    {
        $model = Product::findOrFail($product);
        $this->authorize('delete', $model);
        if ($model->bundleComponents()->whereHas('bundle')->exists() || $model->dependenciesAsRelated()->exists()) {
            throw ValidationException::withMessages([
                'product' => 'Remove this product from bundles and dependency rules before deleting it.',
            ]);
        }
        $this->catalog->delete($model, 'product_deleted');

        return ApiResponse::success(['deleted' => true]);
    }

    public function sync(int $product): JsonResponse
    {
        $model = Product::findOrFail($product);
        $this->authorize('update', $model);
        abort_unless(request()->user()->hasPermission('erp_sync.manage'), 403);

        return ApiResponse::success($this->erp->queue('product', $model), [], 202);
    }
}
