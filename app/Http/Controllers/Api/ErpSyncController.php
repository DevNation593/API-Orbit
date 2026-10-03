<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncErpEntityJob;
use App\Models\ErpSync;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ErpSync::class);
        $data = $request->validate([
            'status' => ['nullable', 'in:queued,processing,completed,failed'], 'entity_type' => ['nullable', 'in:product,quote'],
            'entity_id' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = ErpSync::query()->with('integration:id,provider,name,status')->withCount('logs');
        foreach (['status', 'entity_type', 'entity_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return ApiResponse::paginated($query->latest('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function show(int $sync): JsonResponse
    {
        $model = ErpSync::with(['integration:id,provider,name,status', 'logs'])->findOrFail($sync);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function retry(int $sync): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('erp_sync.manage'), 403);
        $model = ErpSync::findOrFail($sync);
        if ($model->status === 'completed') {
            return ApiResponse::success($model, ['replayed' => true]);
        }
        $model->update(['status' => 'queued', 'error' => null]);
        SyncErpEntityJob::dispatch((int) $model->tenant_id, (int) $model->id)->onQueue('integrations');

        return ApiResponse::success($model->fresh(), [], 202);
    }
}
