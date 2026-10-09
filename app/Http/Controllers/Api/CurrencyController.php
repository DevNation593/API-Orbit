<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrencyRequest;
use App\Models\Currency;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CurrencyController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Currency::class);
        $query = Currency::query();
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::success($query->orderByDesc('is_base')->orderBy('code')->get());
    }

    public function store(CurrencyRequest $request): JsonResponse
    {
        $this->authorize('create', Currency::class);

        return ApiResponse::success($this->catalog->saveCurrency(new Currency, $request->validated()), [], 201);
    }

    public function show(int $currency): JsonResponse
    {
        $model = Currency::findOrFail($currency);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(CurrencyRequest $request, int $currency): JsonResponse
    {
        $model = Currency::findOrFail($currency);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveCurrency($model, $request->validated()));
    }

    public function destroy(int $currency): JsonResponse
    {
        $model = Currency::findOrFail($currency);
        $this->authorize('delete', $model);
        $referenced = collect(['products', 'priceLists', 'taxes', 'discounts', 'pricingRules', 'discountRules', 'quotes'])
            ->contains(fn (string $relation): bool => $model->{$relation}()->exists());
        if ($model->is_base || $referenced) {
            throw ValidationException::withMessages(['currency' => 'A base or referenced currency cannot be deleted.']);
        }
        $this->catalog->delete($model, 'currency_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
