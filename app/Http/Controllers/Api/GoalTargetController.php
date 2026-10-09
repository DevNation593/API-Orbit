<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoalTargetRequest;
use App\Models\Goal;
use App\Models\GoalTarget;
use App\Services\GoalProgressService;
use App\Services\GoalService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalTargetController extends Controller
{
    public function __construct(
        private readonly GoalService $goals,
        private readonly GoalProgressService $progress,
    ) {}

    public function store(GoalTargetRequest $request, int $goal): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('update', $model);

        return ApiResponse::success($this->goals->saveTarget($model, new GoalTarget, $request->validated()), [], 201);
    }

    public function update(GoalTargetRequest $request, int $goal, int $target): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('update', $model);
        $goalTarget = GoalTarget::query()->where('goal_id', $model->id)->findOrFail($target);

        return ApiResponse::success($this->goals->saveTarget($model, $goalTarget, $request->validated()));
    }

    public function destroy(int $goal, int $target): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('update', $model);
        $goalTarget = GoalTarget::query()->where('goal_id', $model->id)->findOrFail($target);
        $this->goals->deleteTarget($model, $goalTarget);

        return ApiResponse::success(['deleted' => true]);
    }

    public function refresh(Request $request, int $goal, int $target): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('update', $model);
        $goalTarget = GoalTarget::query()->where('goal_id', $model->id)->findOrFail($target);
        $data = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']]);

        return ApiResponse::success($this->progress->refreshTarget($goalTarget, isset($data['as_of_date']) ? CarbonImmutable::parse($data['as_of_date']) : null));
    }
}
