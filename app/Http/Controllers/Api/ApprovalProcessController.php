<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovalProcessRequest;
use App\Models\ApprovalProcess;
use App\Services\ApprovalEngine;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApprovalProcessController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalProcess::class);
        $query = ApprovalProcess::query()->withCount(['steps', 'requests']);
        if ($request->filled('approvable_type')) {
            $request->validate(['approvable_type' => ['in:quote,discount,deal,contract']]);
            $query->where('approvable_type', $request->input('approvable_type'));
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::success($query->orderBy('approvable_type')->orderBy('priority')->orderBy('name')->orderByDesc('version')->get());
    }

    public function store(ApprovalProcessRequest $request): JsonResponse
    {
        $this->authorize('create', ApprovalProcess::class);

        return ApiResponse::success($this->engine->saveProcess(new ApprovalProcess, $request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $process): JsonResponse
    {
        $model = ApprovalProcess::with(['creator:id,name,email', 'steps.approverUser:id,name,email', 'steps.approverRole:id,name', 'steps.escalationUser:id,name,email', 'steps.escalationRole:id,name', 'rules'])->withCount('requests')->findOrFail($process);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(ApprovalProcessRequest $request, int $process): JsonResponse
    {
        $model = ApprovalProcess::findOrFail($process);
        $this->authorize('update', $model);

        return ApiResponse::success($this->engine->saveProcess($model, $request->validated(), (int) $request->user()->id));
    }

    public function version(Request $request, int $process): JsonResponse
    {
        $source = ApprovalProcess::with('steps')->findOrFail($process);
        $this->authorize('update', $source);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:160']]);
        $copy = $source->only(['name', 'approvable_type', 'priority', 'conditions', 'match_type', 'settings']);
        $copy['name'] = $data['name'] ?? $source->name;
        $copy['version'] = (int) ApprovalProcess::query()->where('name', $copy['name'])->max('version') + 1;
        $copy['active'] = false;
        $copy['steps'] = $source->steps->map->only([
            'name', 'position', 'approver_type', 'approver_user_id', 'approver_role_id', 'approver_permission',
            'minimum_approvals', 'decision_mode', 'due_hours', 'escalation_user_id', 'escalation_role_id', 'conditions',
        ])->all();

        return ApiResponse::success($this->engine->saveProcess(new ApprovalProcess, $copy, (int) $request->user()->id), [], 201);
    }

    public function destroy(int $process): JsonResponse
    {
        $model = ApprovalProcess::findOrFail($process);
        $this->authorize('delete', $model);
        if ($model->requests()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['process' => 'A process with pending approvals cannot be deleted.']);
        }
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('approval_process_deleted', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
