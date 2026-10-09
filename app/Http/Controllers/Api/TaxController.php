<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaxRequest;
use App\Models\Tax;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaxController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tax::class);
        $query = Tax::query()->with('currency:id,code,symbol');
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::success($query->orderBy('priority')->orderBy('name')->get());
    }

    public function store(TaxRequest $request): JsonResponse
    {
        $this->authorize('create', Tax::class);

        return ApiResponse::success($this->catalog->saveSimple(new Tax, $request->validated(), 'tax'), [], 201);
    }

    public function show(int $tax): JsonResponse
    {
        $model = Tax::with('currency:id,code,symbol')->findOrFail($tax);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(TaxRequest $request, int $tax): JsonResponse
    {
        $model = Tax::findOrFail($tax);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveSimple($model, $request->validated(), 'tax'));
    }

    public function destroy(int $tax): JsonResponse
    {
        $model = Tax::findOrFail($tax);
        $this->authorize('delete', $model);
        if ($model->products()->exists()) {
            throw ValidationException::withMessages(['tax' => 'Detach this tax from products before deleting it.']);
        }
        $this->catalog->delete($model, 'tax_deleted');

        return ApiResponse::success(['deleted' => true]);
    }
}
