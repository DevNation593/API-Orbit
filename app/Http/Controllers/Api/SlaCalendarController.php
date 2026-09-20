<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SlaCalendarRequest;
use App\Models\SlaBusinessCalendar;
use App\Services\SlaConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SlaCalendarController extends Controller
{
    public function __construct(private readonly SlaConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sla.view'), 403);
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = SlaBusinessCalendar::query();
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', $data['is_active']);
        }

        return ApiResponse::paginated($query->orderBy('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(SlaCalendarRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sla.manage'), 403);

        return ApiResponse::success($this->configuration->saveCalendar($request->validated()), [], 201);
    }

    public function show(int $calendar): JsonResponse
    {
        $model = SlaBusinessCalendar::findOrFail($calendar);
        Gate::authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(SlaCalendarRequest $request, int $calendar): JsonResponse
    {
        $model = SlaBusinessCalendar::findOrFail($calendar);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveCalendar($request->validated(), $model));
    }

    public function destroy(int $calendar): JsonResponse
    {
        $model = SlaBusinessCalendar::findOrFail($calendar);
        Gate::authorize('delete', $model);
        $this->configuration->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
