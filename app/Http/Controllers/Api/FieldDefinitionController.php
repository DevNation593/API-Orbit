<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FieldDefinitionRequest;
use App\Models\EntityDefinition;
use App\Models\FieldDefinition;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FieldDefinitionController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(FieldDefinitionRequest $request): JsonResponse
    {
        $this->authorize('viewAny', FieldDefinition::class);
        $query = FieldDefinition::query()->orderBy('entity_type')->orderBy('entity_definition_id')->orderBy('position');
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->string('entity_type')->toString());
        }
        if ($request->filled('entity_definition_id')) {
            $query->where('entity_definition_id', $request->integer('entity_definition_id'));
        }

        return ApiResponse::success($query->get());
    }

    public function store(FieldDefinitionRequest $request): JsonResponse
    {
        $this->authorize('create', FieldDefinition::class);
        $data = $request->validated();
        $this->assertTarget($data);
        $data = $this->normaliseTarget($data);
        $this->assertTypeOptions($data);
        $field = FieldDefinition::create($data);
        $this->audit->record('create', $field, newValues: $field->getAttributes());

        return ApiResponse::success($field, [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $field = FieldDefinition::findOrFail($id);
        $this->authorize('view', $field);

        return ApiResponse::success($field);
    }

    public function update(FieldDefinitionRequest $request, int $id): JsonResponse
    {
        $field = FieldDefinition::findOrFail($id);
        $this->authorize('update', $field);
        $data = $request->validated();
        $this->assertTarget($data);
        $data = $this->normaliseTarget($data);
        $this->assertTypeOptions($data, $field->type, $field->options);
        $old = $field->getAttributes();
        $field->update($data);
        $this->audit->record('update', $field, oldValues: $old, newValues: $field->getAttributes());

        return ApiResponse::success($field);
    }

    public function destroy(int $id): JsonResponse
    {
        $field = FieldDefinition::findOrFail($id);
        $this->authorize('delete', $field);
        $old = $field->getAttributes();
        $field->delete();
        $this->audit->record('delete', $field, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function assertTypeOptions(array $data, ?string $existingType = null, ?array $existingOptions = null): void
    {
        $type = $data['type'] ?? $existingType;
        $options = array_key_exists('options', $data) ? ($data['options'] ?? []) : ($existingOptions ?? []);
        if (in_array($type, ['select', 'multi_select'], true) && count($options) === 0) {
            abort(422, 'Select fields require at least one option.');
        }
    }

    private function normaliseTarget(array $data): array
    {
        if (! empty($data['entity_type'])) {
            $data['entity_definition_id'] = null;
        } elseif (! empty($data['entity_definition_id'])) {
            $data['entity_type'] = null;
        }

        return $data;
    }

    private function assertTarget(array $data): void
    {
        $entityType = $data['entity_type'] ?? null;
        $definitionId = $data['entity_definition_id'] ?? null;

        if (($entityType === null) === ($definitionId === null)) {
            throw ValidationException::withMessages([
                'entity_type' => 'Provide either entity_type or entity_definition_id, but not both.',
            ]);
        }

        if ($definitionId !== null && ! EntityDefinition::query()->where('active', true)->whereKey($definitionId)->exists()) {
            throw ValidationException::withMessages([
                'entity_definition_id' => 'The custom entity does not exist in the active tenant.',
            ]);
        }
    }
}
