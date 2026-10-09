<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoalRequest;
use App\Models\Goal;
use App\Services\GoalProgressService;
use App\Services\GoalService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function __construct(
        private readonly GoalService $goals,
        private readonly GoalProgressService $progress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Goal::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'metric' => ['nullable', 'in:revenue,deals_won,deals_created,calls,meetings,new_customers,quotes,activities'],
            'status' => ['nullable', 'in:draft,active,completed,cancelled'],
            'starts_before' => ['nullable', 'date_format:Y-m-d'],
            'ends_after' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Goal::query()
            ->with(['currency:id,code,name,symbol,decimal_places', 'creator:id,name,email'])
            ->withCount('targets');
        foreach (['metric', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['starts_before'])) {
            $query->whereDate('starts_at', '<=', $data['starts_before']);
        }
        if (isset($data['ends_after'])) {
            $query->whereDate('ends_at', '>=', $data['ends_after']);
        }
        if (filled($data['q'] ?? null)) {
            $query->where('name', 'like', '%'.addcslashes((string) $data['q'], '%_\\').'%');
        }

        return ApiResponse::paginated($query->latest('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $this->authorize('create', Goal::class);

        return ApiResponse::success($this->goals->saveGoal(new Goal, $request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $goal): JsonResponse
    {
        $model = Goal::query()->with([
            'currency:id,code,name,symbol,decimal_places', 'creator:id,name,email',
            'targets' => fn ($query) => $query->orderBy('target_type')->orderBy('target_id')->orderBy('target_key'),
            'targets.progress',
        ])->findOrFail($goal);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(GoalRequest $request, int $goal): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('update', $model);

        return ApiResponse::success($this->goals->saveGoal($model, $request->validated(), (int) $request->user()->id));
    }

    public function destroy(int $goal): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('delete', $model);
        $this->goals->deleteGoal($model);

        return ApiResponse::success(['deleted' => true]);
    }

    public function refresh(Request $request, int $goal): JsonResponse
    {
        $model = Goal::query()->findOrFail($goal);
        $this->authorize('update', $model);
        $data = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']]);

        return ApiResponse::success($this->progress->refresh($model, isset($data['as_of_date']) ? CarbonImmutable::parse($data['as_of_date']) : null));
    }
}
