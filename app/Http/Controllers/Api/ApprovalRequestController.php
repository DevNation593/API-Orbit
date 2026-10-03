<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovalDecisionRequest;
use App\Models\ApprovalRequest;
use App\Services\ApprovalEngine;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalRequestController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalRequest::class);
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,cancelled,expired'],
            'approvable_type' => ['nullable', 'in:quote,discount,deal,contract'],
            'process_id' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = ApprovalRequest::query()->with(['process:id,name,approvable_type,version', 'requester:id,name,email'])->withCount('decisions');
        foreach (['status', 'approvable_type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['process_id'])) {
            $query->where('approval_process_id', $data['process_id']);
        }

        return ApiResponse::paginated($query->latest('requested_at')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function show(int $approval): JsonResponse
    {
        $model = ApprovalRequest::with($this->engine->requestRelations())->findOrFail($approval);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function decide(ApprovalDecisionRequest $request, int $approval): JsonResponse
    {
        $model = ApprovalRequest::findOrFail($approval);
        $this->authorize('decide', $model);
        $data = $request->validated();
        $result = $this->engine->decide($model, $request->user(), $data['decision'], $data['comment'] ?? null);

        return ApiResponse::success($result['request'], ['decision' => $result['decision'], 'replayed' => $result['replayed']]);
    }

    public function cancel(Request $request, int $approval): JsonResponse
    {
        $model = ApprovalRequest::findOrFail($approval);
        $this->authorize('cancel', $model);

        return ApiResponse::success($this->engine->cancel($model, $request->user()));
    }
}
