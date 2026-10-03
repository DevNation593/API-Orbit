<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Requests\StructureMemberRequest;
use App\Models\Branch;
use App\Services\SalesStructureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(private readonly SalesStructureService $structure) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'currency_id' => ['nullable', 'integer', 'min:1'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Branch::query()
            ->with(['parent:id,code,name', 'currency:id,code,name,symbol', 'manager:id,name,email'])
            ->withCount(['children', 'teams', 'territories', 'members']);
        foreach (['parent_id', 'currency_id', 'manager_id'] as $field) {
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

        return ApiResponse::paginated($query->orderBy('name')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        return ApiResponse::success($this->structure->saveBranch(new Branch, $request->validated()), [], 201);
    }

    public function show(int $branch): JsonResponse
    {
        $model = Branch::query()->with([
            'parent:id,code,name', 'children:id,parent_id,code,name,active',
            'currency:id,code,name,symbol,decimal_places', 'manager:id,name,email',
            'teams:id,branch_id,name,active', 'territories:id,branch_id,code,name,active',
            'members.user:id,name,email',
        ])->findOrFail($branch);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(BranchRequest $request, int $branch): JsonResponse
    {
        $model = Branch::query()->findOrFail($branch);
        $this->authorize('update', $model);

        return ApiResponse::success($this->structure->saveBranch($model, $request->validated()));
    }

    public function destroy(int $branch): JsonResponse
    {
        $model = Branch::query()->findOrFail($branch);
        $this->authorize('delete', $model);
        $this->structure->deleteBranch($model);

        return ApiResponse::success(['deleted' => true]);
    }

    public function members(Request $request, int $branch): JsonResponse
    {
        $model = Branch::query()->findOrFail($branch);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->members()->with('user:id,name,email')->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function saveMember(StructureMemberRequest $request, int $branch): JsonResponse
    {
        $model = Branch::query()->findOrFail($branch);
        $this->authorize('update', $model);

        return ApiResponse::success($this->structure->saveMember($model, $request->validated()), [], 201);
    }

    public function updateMember(StructureMemberRequest $request, int $branch, int $user): JsonResponse
    {
        $model = Branch::query()->findOrFail($branch);
        $this->authorize('update', $model);

        return ApiResponse::success($this->structure->saveMember($model, $request->validated()));
    }

    public function removeMember(int $branch, int $user): JsonResponse
    {
        $model = Branch::query()->findOrFail($branch);
        $this->authorize('update', $model);
        $this->structure->removeMember($model, $user);

        return ApiResponse::success(['deleted' => true]);
    }
}
