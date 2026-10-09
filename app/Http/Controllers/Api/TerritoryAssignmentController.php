<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TerritoryAssignmentRequest;
use App\Models\TerritoryAssignment;
use App\Services\TerritoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerritoryAssignmentController extends Controller
{
    public function __construct(private readonly TerritoryService $territories) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TerritoryAssignment::class);
        $data = $request->validate([
            'territory_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'in:contact,organization,lead,deal'],
            'source' => ['nullable', 'in:manual,rule'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = TerritoryAssignment::query()->with([
            'territory:id,code,name', 'rule:id,name', 'assigner:id,name,email', 'assignable',
        ]);
        if (isset($data['territory_id'])) {
            $query->where('territory_id', $data['territory_id']);
        }
        if (isset($data['entity_type'])) {
            $query->where('assignable_type', $data['entity_type']);
        }
        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        return ApiResponse::paginated($query->latest('assigned_at')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(TerritoryAssignmentRequest $request): JsonResponse
    {
        $this->authorize('create', TerritoryAssignment::class);
        $data = $request->validated();
        $result = $this->territories->assign(
            $data['entity_type'],
            (int) $data['entity_id'],
            isset($data['territory_id']) ? (int) $data['territory_id'] : null,
            (bool) ($data['evaluate_rules'] ?? false),
            (int) $request->user()->id,
        );

        return ApiResponse::success($result, [], 201);
    }

    public function destroy(int $assignment): JsonResponse
    {
        $model = TerritoryAssignment::query()->with('assignable')->findOrFail($assignment);
        $this->authorize('delete', $model);
        abort_if($model->assignable === null, 404, 'Assigned resource not found.');
        $this->territories->assign(
            $model->assignable->getMorphClass(),
            (int) $model->assignable->getKey(),
            null,
            false,
            (int) request()->user()->id,
        );

        return ApiResponse::success(['deleted' => true]);
    }
}
