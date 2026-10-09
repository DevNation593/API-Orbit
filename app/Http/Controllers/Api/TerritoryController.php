<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StructureMemberRequest;
use App\Http\Requests\TerritoryRequest;
use App\Models\Territory;
use App\Services\TerritoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerritoryController extends Controller
{
    public function __construct(private readonly TerritoryService $territories) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Territory::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'in:geographic,named,account,product,custom'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Territory::query()
            ->with(['parent:id,code,name', 'branch:id,code,name', 'manager:id,name,email'])
            ->withCount(['children', 'members', 'rules', 'assignments', 'deals']);
        foreach (['parent_id', 'branch_id', 'manager_id', 'type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if (filled($data['q'] ?? null)) {
            $term = '%'.addcslashes((string) $data['q'], '%_\\').'%';
            $query->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        return ApiResponse::paginated($query->orderBy('position')->orderBy('name')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(TerritoryRequest $request): JsonResponse
    {
        $this->authorize('create', Territory::class);

        return ApiResponse::success($this->territories->saveTerritory(new Territory, $request->validated()), [], 201);
    }

    public function show(int $territory): JsonResponse
    {
        $model = Territory::query()->with([
            'parent:id,code,name', 'children:id,parent_id,code,name,position,active',
            'branch:id,code,name', 'manager:id,name,email', 'members.user:id,name,email', 'rules',
        ])->withCount(['assignments', 'deals'])->findOrFail($territory);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(TerritoryRequest $request, int $territory): JsonResponse
    {
        $model = Territory::query()->findOrFail($territory);
        $this->authorize('update', $model);

        return ApiResponse::success($this->territories->saveTerritory($model, $request->validated()));
    }

    public function destroy(int $territory): JsonResponse
    {
        $model = Territory::query()->findOrFail($territory);
        $this->authorize('delete', $model);
        $this->territories->deleteTerritory($model);

        return ApiResponse::success(['deleted' => true]);
    }

    public function members(Request $request, int $territory): JsonResponse
    {
        $model = Territory::query()->findOrFail($territory);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->members()->with('user:id,name,email')->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function saveMember(StructureMemberRequest $request, int $territory): JsonResponse
    {
        $model = Territory::query()->findOrFail($territory);
        $this->authorize('update', $model);

        return ApiResponse::success($this->territories->saveMember($model, $request->validated()), [], 201);
    }

    public function updateMember(StructureMemberRequest $request, int $territory, int $user): JsonResponse
    {
        $model = Territory::query()->findOrFail($territory);
        $this->authorize('update', $model);

        return ApiResponse::success($this->territories->saveMember($model, $request->validated()));
    }

    public function removeMember(int $territory, int $user): JsonResponse
    {
        $model = Territory::query()->findOrFail($territory);
        $this->authorize('update', $model);
        $this->territories->removeMember($model, $user);

        return ApiResponse::success(['deleted' => true]);
    }
}
