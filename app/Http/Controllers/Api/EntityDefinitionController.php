<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityDefinitionRequest;
use App\Models\EntityDefinition;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;

class EntityDefinitionController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(EntityDefinitionRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EntityDefinition::class);

        return ApiResponse::success(EntityDefinition::query()->with(['fields' => fn ($query) => $query->where('active', true)->orderBy('position')])->where('active', true)->orderBy('name')->get());
    }

    public function store(EntityDefinitionRequest $request): JsonResponse
    {
        $this->authorize('create', EntityDefinition::class);
        $entity = EntityDefinition::create($request->validated());
        $this->audit->record('create', $entity, newValues: $entity->getAttributes());

        return ApiResponse::success($entity->load('fields'), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $entity = EntityDefinition::query()->with('fields')->findOrFail($id);
        $this->authorize('view', $entity);

        return ApiResponse::success($entity);
    }

    public function update(EntityDefinitionRequest $request, int $id): JsonResponse
    {
        $entity = EntityDefinition::findOrFail($id);
        $this->authorize('update', $entity);
        $old = $entity->getAttributes();
        $data = $request->validated();
        $entity->update($data);
        $this->audit->record('update', $entity, oldValues: $old, newValues: $entity->getAttributes());

        return ApiResponse::success($entity->fresh()->load('fields'));
    }

    public function destroy(int $id): JsonResponse
    {
        $entity = EntityDefinition::findOrFail($id);
        $this->authorize('delete', $entity);
        $old = $entity->getAttributes();
        $entity->delete();
        $this->audit->record('delete', $entity, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
