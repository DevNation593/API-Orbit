<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BundleRequest;
use App\Models\Bundle;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BundleController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bundle::class);
        $query = Bundle::query()->with('product:id,sku,name,currency_id,base_price')->withCount('items');
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::success($query->orderBy('name')->get());
    }

    public function store(BundleRequest $request): JsonResponse
    {
        $this->authorize('create', Bundle::class);

        return ApiResponse::success($this->catalog->saveBundle(new Bundle, $request->validated()), [], 201);
    }

    public function show(int $bundle): JsonResponse
    {
        $model = Bundle::with(['product.currency', 'items.product.currency', 'items.variant', 'rules'])->findOrFail($bundle);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(BundleRequest $request, int $bundle): JsonResponse
    {
        $model = Bundle::findOrFail($bundle);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveBundle($model, $request->validated()));
    }

    public function destroy(int $bundle): JsonResponse
    {
        $model = Bundle::findOrFail($bundle);
        $this->authorize('delete', $model);
        $this->catalog->delete($model, 'bundle_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
