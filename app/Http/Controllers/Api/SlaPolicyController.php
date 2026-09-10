<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SlaPolicyRequest;
use App\Models\SlaPolicy;
use App\Services\SlaConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SlaPolicyController extends Controller
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
        $query = SlaPolicy::query()->with('rules');
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', $data['is_active']);
        }

        return ApiResponse::paginated($query->orderBy('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(SlaPolicyRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sla.manage'), 403);

        return ApiResponse::success($this->configuration->savePolicy($request->validated()), [], 201);
    }

    public function show(int $policy): JsonResponse
    {
        $model = SlaPolicy::findOrFail($policy);
        Gate::authorize('view', $model);

        return ApiResponse::success($model->load('rules'));
    }

    public function update(SlaPolicyRequest $request, int $policy): JsonResponse
    {
        $model = SlaPolicy::findOrFail($policy);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->savePolicy($request->validated(), $model));
    }

    public function destroy(int $policy): JsonResponse
    {
        $model = SlaPolicy::findOrFail($policy);
        Gate::authorize('delete', $model);
        $this->configuration->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
