<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesTeamRequest;
use App\Http\Requests\StructureMemberRequest;
use App\Models\SalesTeam;
use App\Services\SalesStructureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesTeamController extends Controller
{
    public function __construct(private readonly SalesStructureService $structure) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalesTeam::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = SalesTeam::query()
            ->with(['parent:id,name', 'branch:id,code,name', 'manager:id,name,email'])
            ->withCount(['children', 'members', 'deals']);
        foreach (['parent_id', 'branch_id', 'manager_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if (filled($data['q'] ?? null)) {
            $term = '%'.addcslashes((string) $data['q'], '%_\\').'%';
            $query->where('name', 'like', $term);
        }

        return ApiResponse::paginated($query->orderBy('name')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(SalesTeamRequest $request): JsonResponse
    {
        $this->authorize('create', SalesTeam::class);

        return ApiResponse::success($this->structure->saveTeam(new SalesTeam, $request->validated()), [], 201);
    }

    public function show(int $team): JsonResponse
    {
        $model = SalesTeam::query()->with([
            'parent:id,name', 'children:id,parent_id,name,active', 'branch:id,code,name',
            'manager:id,name,email', 'members.user:id,name,email',
        ])->withCount('deals')->findOrFail($team);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(SalesTeamRequest $request, int $team): JsonResponse
    {
        $model = SalesTeam::query()->findOrFail($team);
        $this->authorize('update', $model);

        return ApiResponse::success($this->structure->saveTeam($model, $request->validated()));
    }

    public function destroy(int $team): JsonResponse
    {
        $model = SalesTeam::query()->findOrFail($team);
        $this->authorize('delete', $model);
        $this->structure->deleteTeam($model);

        return ApiResponse::success(['deleted' => true]);
    }

    public function members(Request $request, int $team): JsonResponse
    {
        $model = SalesTeam::query()->findOrFail($team);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->members()->with('user:id,name,email')->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function saveMember(StructureMemberRequest $request, int $team): JsonResponse
    {
        $model = SalesTeam::query()->findOrFail($team);
        $this->authorize('update', $model);

        return ApiResponse::success($this->structure->saveMember($model, $request->validated()), [], 201);
    }

    public function updateMember(StructureMemberRequest $request, int $team, int $user): JsonResponse
    {
        $model = SalesTeam::query()->findOrFail($team);
        $this->authorize('update', $model);

        return ApiResponse::success($this->structure->saveMember($model, $request->validated()));
    }

    public function removeMember(int $team, int $user): JsonResponse
    {
        $model = SalesTeam::query()->findOrFail($team);
        $this->authorize('update', $model);
        $this->structure->removeMember($model, $user);

        return ApiResponse::success(['deleted' => true]);
    }
}
