<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TerritoryRuleRequest;
use App\Models\TerritoryRule;
use App\Services\TerritoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerritoryRuleController extends Controller
{
    public function __construct(private readonly TerritoryService $territories) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TerritoryRule::class);
        $data = $request->validate([
            'territory_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'in:contact,organization,lead,deal'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = TerritoryRule::query()->with('territory:id,code,name');
        foreach (['territory_id', 'entity_type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::paginated($query->orderBy('priority')->orderBy('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(TerritoryRuleRequest $request): JsonResponse
    {
        $this->authorize('create', TerritoryRule::class);

        return ApiResponse::success($this->territories->saveRule(new TerritoryRule, $request->validated()), [], 201);
    }

    public function show(int $rule): JsonResponse
    {
        $model = TerritoryRule::query()->with('territory:id,code,name')->findOrFail($rule);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(TerritoryRuleRequest $request, int $rule): JsonResponse
    {
        $model = TerritoryRule::query()->findOrFail($rule);
        $this->authorize('update', $model);

        return ApiResponse::success($this->territories->saveRule($model, $request->validated()));
    }

    public function destroy(int $rule): JsonResponse
    {
        $model = TerritoryRule::query()->findOrFail($rule);
        $this->authorize('delete', $model);
        $this->territories->deleteRule($model);

        return ApiResponse::success(['deleted' => true]);
    }
}
