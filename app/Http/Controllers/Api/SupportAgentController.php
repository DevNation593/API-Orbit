<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportAgentRequest;
use App\Models\SupportAgent;
use App\Services\SupportConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupportAgentController extends Controller
{
    public function __construct(private readonly SupportConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('support.view'), 403);
        $data = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = SupportAgent::query();
        foreach (['user_id', 'is_active'] as $filter) {
            if (array_key_exists($filter, $data)) {
                $query->where($filter, $data[$filter]);
            }
        }

        return ApiResponse::paginated($query->orderBy('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(SupportAgentRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('support.manage'), 403);

        return ApiResponse::success($this->configuration->saveAgent($request->validated()), [], 201);
    }

    public function show(int $agent): JsonResponse
    {
        $model = SupportAgent::findOrFail($agent);
        Gate::authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(SupportAgentRequest $request, int $agent): JsonResponse
    {
        $model = SupportAgent::findOrFail($agent);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveAgent($request->validated(), $model));
    }

    public function destroy(int $agent): JsonResponse
    {
        $model = SupportAgent::findOrFail($agent);
        Gate::authorize('delete', $model);
        $this->configuration->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
