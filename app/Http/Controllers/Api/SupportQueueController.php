<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportQueueRequest;
use App\Models\SupportQueue;
use App\Services\SupportConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupportQueueController extends Controller
{
    public function __construct(private readonly SupportConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('support.view'), 403);
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = SupportQueue::query()->with('agents');
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', $data['is_active']);
        }

        return ApiResponse::paginated($query->orderBy('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(SupportQueueRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('support.manage'), 403);

        return ApiResponse::success($this->configuration->saveQueue($request->validated()), [], 201);
    }

    public function show(int $queue): JsonResponse
    {
        $model = SupportQueue::findOrFail($queue);
        Gate::authorize('view', $model);

        return ApiResponse::success($model->load('agents'));
    }

    public function update(SupportQueueRequest $request, int $queue): JsonResponse
    {
        $model = SupportQueue::findOrFail($queue);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveQueue($request->validated(), $model));
    }

    public function replaceAgents(Request $request, int $queue): JsonResponse
    {
        $model = SupportQueue::findOrFail($queue);
        Gate::authorize('update', $model);
        $data = $request->validate([
            'agent_ids' => ['present', 'array', 'list', 'max:100'],
            'agent_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return ApiResponse::success($this->configuration->replaceQueueAgents($model, $data['agent_ids']));
    }

    public function destroy(int $queue): JsonResponse
    {
        $model = SupportQueue::findOrFail($queue);
        Gate::authorize('delete', $model);
        $this->configuration->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
