<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovalDelegationRequest;
use App\Models\ApprovalDelegation;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApprovalDelegationController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('approvals.view'), 403);

        return ApiResponse::success(ApprovalDelegation::query()->with(['fromUser:id,name,email', 'toUser:id,name,email'])->orderByDesc('starts_at')->get());
    }

    public function store(ApprovalDelegationRequest $request): JsonResponse
    {
        $model = $this->save(new ApprovalDelegation, $request->validated());

        return ApiResponse::success($model, [], 201);
    }

    public function update(ApprovalDelegationRequest $request, int $delegation): JsonResponse
    {
        return ApiResponse::success($this->save(ApprovalDelegation::findOrFail($delegation), $request->validated()));
    }

    public function destroy(Request $request, int $delegation): JsonResponse
    {
        abort_unless($request->user()->hasPermission('approvals.manage'), 403);
        $model = ApprovalDelegation::findOrFail($delegation);
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('approval_delegation_deleted', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<string, mixed> $data */
    private function save(ApprovalDelegation $model, array $data): ApprovalDelegation
    {
        $from = $data['from_user_id'] ?? $model->from_user_id;
        $to = $data['to_user_id'] ?? $model->to_user_id;
        $starts = $data['starts_at'] ?? $model->starts_at;
        $ends = $data['ends_at'] ?? $model->ends_at;
        $type = $data['approvable_type'] ?? $model->approvable_type;
        if ((int) $from === (int) $to) {
            throw ValidationException::withMessages(['to_user_id' => 'An approver cannot delegate to themselves.']);
        }
        if (CarbonImmutable::parse($ends)->lessThanOrEqualTo(CarbonImmutable::parse($starts))) {
            throw ValidationException::withMessages(['ends_at' => 'The delegation end must be after its start.']);
        }
        $overlap = ApprovalDelegation::query()->where('from_user_id', $from)->where('active', true)
            ->where(fn ($query) => $type === null ? $query->whereNull('approvable_type') : $query->where(fn ($part) => $part->whereNull('approvable_type')->orWhere('approvable_type', $type)))
            ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts);
        if ($model->exists) {
            $overlap->where('id', '!=', $model->id);
        }
        if (($data['active'] ?? $model->active ?? true) && $overlap->exists()) {
            throw ValidationException::withMessages(['starts_at' => 'This approver already has an overlapping active delegation.']);
        }
        $exists = $model->exists;
        $old = $exists ? $model->getAttributes() : null;
        $model->fill($data)->save();
        $this->audit->record($exists ? 'approval_delegation_updated' : 'approval_delegation_created', $model, oldValues: $old, newValues: $model->getAttributes());

        return $model->fresh(['fromUser:id,name,email', 'toUser:id,name,email']);
    }
}
