<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityRecordRequest;
use App\Models\EntityDefinition;
use App\Models\EntityRecord;
use App\Services\CustomFieldService;
use App\Services\DynamicRecordQuery;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;

class EntityRecordController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly DynamicRecordQuery $filters,
        private readonly AuditService $audit,
    ) {}

    public function index(EntityRecordRequest $request, int $entityDefinition): JsonResponse
    {
        $definition = $this->definition($entityDefinition);
        $this->authorize('view', $definition);
        $query = EntityRecord::query()->where('entity_definition_id', $definition->id);
        $query = $this->filters->apply($query, $request, $definition);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(EntityRecordRequest $request, int $entityDefinition): JsonResponse
    {
        $definition = $this->definition($entityDefinition);
        $this->authorize('create', $definition);
        $data = $this->customFields->validateAndNormalise($definition, $request->validated('data'));
        $record = EntityRecord::create(['entity_definition_id' => $definition->id, 'data' => $data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->audit->record('create', $record, newValues: $record->getAttributes());

        return ApiResponse::success($record, [], 201);
    }

    public function show(int $entityDefinition, int $id): JsonResponse
    {
        $definition = $this->definition($entityDefinition);
        $this->authorize('view', $definition);
        $record = EntityRecord::query()->where('entity_definition_id', $definition->id)->findOrFail($id);

        return ApiResponse::success($record);
    }

    public function update(EntityRecordRequest $request, int $entityDefinition, int $id): JsonResponse
    {
        $definition = $this->definition($entityDefinition);
        $this->authorize('update', $definition);
        $record = EntityRecord::query()->where('entity_definition_id', $definition->id)->findOrFail($id);
        $data = $this->customFields->validateAndNormalise($definition, array_merge($record->data ?? [], $request->validated('data')));
        $old = $record->getAttributes();
        $record->update(['data' => $data, 'updated_by' => $request->user()->id]);
        $this->audit->record('update', $record, oldValues: $old, newValues: $record->getAttributes());

        return ApiResponse::success($record);
    }

    public function destroy(int $entityDefinition, int $id): JsonResponse
    {
        $definition = $this->definition($entityDefinition);
        $this->authorize('delete', $definition);
        $record = EntityRecord::query()->where('entity_definition_id', $definition->id)->findOrFail($id);
        $old = $record->getAttributes();
        $record->delete();
        $this->audit->record('delete', $record, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function definition(int $id): EntityDefinition
    {
        return EntityDefinition::query()->where('active', true)->findOrFail($id);
    }
}
