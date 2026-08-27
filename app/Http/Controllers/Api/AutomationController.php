<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationRequest;
use App\Models\Automation;
use App\Services\WorkflowValidator;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;

class AutomationController extends Controller
{
    public function __construct(private readonly WorkflowValidator $validator, private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Automation::class);

        return ApiResponse::success(Automation::query()->withCount('runs')->orderBy('name')->get());
    }

    public function store(AutomationRequest $request): JsonResponse
    {
        $this->authorize('create', Automation::class);
        $data = $request->validated();
        $data['config'] = $this->validator->validate($data['config']);
        $data['event_type'] = $data['config']['trigger']['type'];
        $automation = Automation::create($data);
        $this->audit->record('create', $automation, newValues: $automation->getAttributes());

        return ApiResponse::success($automation, [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $automation = Automation::findOrFail($id);
        $this->authorize('view', $automation);

        return ApiResponse::success($automation);
    }

    public function update(AutomationRequest $request, int $id): JsonResponse
    {
        $automation = Automation::findOrFail($id);
        $this->authorize('update', $automation);
        $data = $request->validated();
        $data['config'] = $this->validator->validate($data['config']);
        $data['event_type'] = $data['config']['trigger']['type'];
        $data['version'] = max((int) ($data['version'] ?? $automation->version + 1), $automation->version + 1);
        $old = $automation->getAttributes();
        $automation->update($data);
        $this->audit->record('update', $automation, oldValues: $old, newValues: $automation->getAttributes());

        return ApiResponse::success($automation);
    }

    public function destroy(int $id): JsonResponse
    {
        $automation = Automation::findOrFail($id);
        $this->authorize('delete', $automation);
        $old = $automation->getAttributes();
        $automation->delete();
        $this->audit->record('delete', $automation, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
