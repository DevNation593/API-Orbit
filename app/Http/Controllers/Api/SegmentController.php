<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SegmentRequest;
use App\Jobs\ProcessMarketingJob;
use App\Models\Audience;
use App\Models\Segment;
use App\Services\SegmentEngine;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SegmentController extends Controller
{
    public function __construct(private readonly SegmentEngine $engine, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(Segment::withCount('members')->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function fields(Request $request): JsonResponse
    {
        $data = $request->validate(['entity_type' => ['required', 'string'], 'entity_definition_id' => ['nullable', 'integer', 'min:1']]);

        return ApiResponse::success($this->engine->fields($data['entity_type'], $data['entity_definition_id'] ?? null));
    }

    public function preview(SegmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = $this->engine->query($data['entity_type'], $data['definition'], $data['entity_definition_id'] ?? null);

        return ApiResponse::paginated($query->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function store(SegmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->engine->query($data['entity_type'], $data['definition'], $data['entity_definition_id'] ?? null);
        $model = Segment::create($data + ['created_by' => $request->user()->id]);
        $this->audit->record('segment_created', $model);

        return ApiResponse::success($model, [], 201);
    }

    public function show(int $segment): JsonResponse
    {
        $model = Segment::withCount('members')->findOrFail($segment);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(SegmentRequest $request, int $segment): JsonResponse
    {
        return DB::transaction(function () use ($request, $segment): JsonResponse {
            $model = Segment::whereKey($segment)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $model);
            $data = array_replace($model->only(['name', 'description', 'entity_type', 'entity_definition_id', 'definition', 'active']), $request->validated());
            abort_unless($data['entity_type'] === $model->entity_type && (int) $data['entity_definition_id'] === (int) $model->entity_definition_id, 409, 'A segment entity type cannot be changed.');
            $this->engine->query($data['entity_type'], $data['definition'], $data['entity_definition_id']);
            $model->update($data + ['revision' => $model->revision + 1, 'refreshed_at' => null]);
            $model->members()->delete();
            $this->audit->record('segment_updated', $model);

            return ApiResponse::success($model->fresh());
        });
    }

    public function members(Request $request, int $segment): JsonResponse
    {
        $model = Segment::findOrFail($segment);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->members()->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function refresh(int $segment): JsonResponse
    {
        $model = Segment::findOrFail($segment);
        $this->authorize('update', $model);
        abort_unless($model->active, 409, 'The segment is inactive.');
        ProcessMarketingJob::dispatch((int) $model->tenant_id, 'segment', (int) $model->id)->afterCommit();

        return ApiResponse::success(['queued' => true, 'segment_id' => $model->id], [], 202);
    }

    public function destroy(int $segment): JsonResponse
    {
        $model = Segment::findOrFail($segment);
        $this->authorize('delete', $model);
        abort_if(Audience::where('segment_id', $model->id)->exists(), 409, 'An audience still references this segment.');
        $model->delete();
        $this->audit->record('segment_deleted', $model);

        return ApiResponse::success(['deleted' => true]);
    }
}
