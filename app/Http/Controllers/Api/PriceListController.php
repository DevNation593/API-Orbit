<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceListRequest;
use App\Models\PriceList;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PriceList::class);
        $query = PriceList::query()->with('currency:id,code,name,symbol')->withCount('items');
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if ($request->filled('currency_id')) {
            $request->validate(['currency_id' => ['integer', 'min:1']]);
            $query->where('currency_id', $request->integer('currency_id'));
        }

        return ApiResponse::success($query->orderByDesc('is_default')->orderBy('priority')->orderBy('name')->get());
    }

    public function store(PriceListRequest $request): JsonResponse
    {
        $this->authorize('create', PriceList::class);

        return ApiResponse::success($this->catalog->savePriceList(new PriceList, $request->validated()), [], 201);
    }

    public function show(int $priceList): JsonResponse
    {
        $model = PriceList::with(['currency', 'items.product:id,sku,name', 'items.variant:id,product_id,sku,name'])->findOrFail($priceList);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(PriceListRequest $request, int $priceList): JsonResponse
    {
        $model = PriceList::findOrFail($priceList);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->savePriceList($model, $request->validated()));
    }

    public function destroy(int $priceList): JsonResponse
    {
        $model = PriceList::findOrFail($priceList);
        $this->authorize('delete', $model);
        $this->catalog->delete($model, 'price_list_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
