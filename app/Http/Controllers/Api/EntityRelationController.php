<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityRelationRequest;
use App\Models\EntityRelation;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use App\Support\TenantRelationResolver;
use Illuminate\Http\JsonResponse;

class EntityRelationController extends Controller
{
    public function __construct(
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly TenantRelationResolver $relations,
    ) {}

    public function index(EntityRelationRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('relations.view'), 403, 'You do not have permission to view relations.');
        $query = EntityRelation::query();
        $query = $this->filters->apply($query, $request, [
            'relation_type' => 'entity_relations.relation_type',
            'from_type' => 'entity_relations.from_type', 'from_id' => 'entity_relations.from_id',
            'to_type' => 'entity_relations.to_type', 'to_id' => 'entity_relations.to_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(EntityRelationRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('relations.manage'), 403, 'You do not have permission to manage relations.');
        $data = $request->validated();
        $data['from_type'] = $this->relations->canonicalEntityType($data['from_type'], $data['from_id']);
        $data['to_type'] = $this->relations->canonicalEntityType($data['to_type'], $data['to_id']);
        $relation = EntityRelation::create($data);
        $this->audit->record('create', $relation, newValues: $relation->getAttributes());

        return ApiResponse::success($relation, [], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('relations.manage'), 403, 'You do not have permission to manage relations.');
        $relation = EntityRelation::findOrFail($id);
        $old = $relation->getAttributes();
        $relation->delete();
        $this->audit->record('delete', $relation, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
