<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForecastRequest;
use App\Models\ForecastSnapshot;
use App\Services\ForecastService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ForecastController extends Controller
{
    public function __construct(private readonly ForecastService $forecast) {}

    public function summary(ForecastRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('forecast.view'), 403);

        return ApiResponse::success($this->forecast->summary($request->validated()));
    }

    public function users(ForecastRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('forecast.view'), 403);

        return ApiResponse::success($this->forecast->users($request->validated()));
    }

    public function teams(ForecastRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('forecast.view'), 403);

        return ApiResponse::success($this->forecast->teams($request->validated()));
    }

    public function snapshots(ForecastRequest $request): JsonResponse
    {
        $this->authorize('viewAny', ForecastSnapshot::class);
        $data = $request->validated();
        $query = ForecastSnapshot::query()->with([
            'currency:id,code,name,symbol,decimal_places', 'generator:id,name,email', 'pipelineModel:id,name',
        ]);
        foreach (['pipeline_id', 'currency_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['starts_at'])) {
            $query->whereDate('as_of_date', '>=', $data['starts_at']);
        }
        if (isset($data['ends_at'])) {
            $query->whereDate('as_of_date', '<=', $data['ends_at']);
        }

        return ApiResponse::paginated($query->latest('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function snapshot(ForecastRequest $request): JsonResponse
    {
        $this->authorize('create', ForecastSnapshot::class);

        return ApiResponse::success($this->forecast->snapshot($request->validated(), (int) $request->user()->id), [], 201);
    }
}
