<?php

namespace App\Http\Controllers\Api;

use App\Events\TaskCompleted;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Models\Task;
use App\Services\CustomFieldService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use App\Support\TenantRelationResolver;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    public function __construct(
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly TenantRelationResolver $relations,
        private readonly CustomFieldService $customFields,
    ) {}

    public function index(TaskRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);
        $query = Task::query()->with(['assignee:id,name', 'creator:id,name']);
        $query = $this->filters->apply($query, $request, [
            'title' => 'tasks.title', 'status' => 'tasks.status', 'priority' => 'tasks.priority',
            'assigned_to' => 'tasks.assigned_to', 'due_at' => 'tasks.due_at',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(TaskRequest $request): JsonResponse
    {
        $this->authorize('create', Task::class);
        $data = $request->validated();
        $data['related_type'] = $this->relations->canonicalMorphType(
            $data['related_type'] ?? null,
            $data['related_id'] ?? null,
        );
        $data['custom_fields'] = $this->customFields->validateAndNormalise('tasks', $data['custom_fields'] ?? []);
        $data['created_by'] = $request->user()->id;
        $task = Task::create($data);
        $this->audit->record('create', $task, newValues: $task->getAttributes());

        return ApiResponse::success($task->load(['assignee:id,name', 'creator:id,name']), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $task = Task::query()->with(['assignee:id,name', 'creator:id,name'])->findOrFail($id);
        $this->authorize('view', $task);

        return ApiResponse::success($task);
    }

    public function update(TaskRequest $request, int $id): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->authorize('update', $task);
        $data = $request->validated();
        if (array_key_exists('related_type', $data) || array_key_exists('related_id', $data)) {
            $data['related_type'] = $this->relations->canonicalMorphType(
                $data['related_type'] ?? null,
                $data['related_id'] ?? null,
            );
        }
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = $this->customFields->validateAndNormalise(
                'tasks',
                array_merge($task->custom_fields ?? [], $data['custom_fields'] ?? []),
            );
        }
        $wasCompleted = $task->status === 'completed';
        $old = $task->getAttributes();
        if (($data['status'] ?? null) === 'completed' && $task->completed_at === null) {
            $data['completed_at'] = now();
        }
        $task->update($data);
        $this->audit->record('update', $task, oldValues: $old, newValues: $task->getAttributes());
        if (! $wasCompleted && $task->status === 'completed') {
            TaskCompleted::dispatch($task);
        }

        return ApiResponse::success($task->fresh()->load(['assignee:id,name', 'creator:id,name']));
    }

    public function destroy(int $id): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->authorize('delete', $task);
        $old = $task->getAttributes();
        $task->delete();
        $this->audit->record('delete', $task, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
