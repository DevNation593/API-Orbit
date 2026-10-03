<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscountRequest;
use App\Models\Discount;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Discount::class);
        $query = Discount::query()->with('currency:id,code,symbol');
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::success($query->orderBy('name')->get());
    }

    public function store(DiscountRequest $request): JsonResponse
    {
        $this->authorize('create', Discount::class);

        return ApiResponse::success($this->catalog->saveSimple(new Discount, $request->validated(), 'discount'), [], 201);
    }

    public function show(int $discount): JsonResponse
    {
        $model = Discount::with('currency:id,code,symbol')->findOrFail($discount);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(DiscountRequest $request, int $discount): JsonResponse
    {
        $model = Discount::findOrFail($discount);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveSimple($model, $request->validated(), 'discount'));
    }

    public function destroy(int $discount): JsonResponse
    {
        $model = Discount::findOrFail($discount);
        $this->authorize('delete', $model);
        $this->catalog->delete($model, 'discount_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
