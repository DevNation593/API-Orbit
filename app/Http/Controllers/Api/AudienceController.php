<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AudienceRequest;
use App\Jobs\ProcessMarketingJob;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Segment;
use App\Services\MarketingAudienceService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AudienceController extends Controller
{
    public function __construct(private readonly MarketingAudienceService $audiences, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(Audience::with('segment')->withCount('members')->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function show(int $audience): JsonResponse
    {
        $model = Audience::with('segment')->withCount('members')->findOrFail($audience);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function store(AudienceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->validateSegment($data);
        $model = Audience::create($data + ['created_by' => $request->user()->id]);
        $this->audit->record('audience_created', $model);

        return ApiResponse::success($model, [], 201);
    }

    public function update(AudienceRequest $request, int $audience): JsonResponse
    {
        return DB::transaction(function () use ($request, $audience): JsonResponse {
            $model = Audience::whereKey($audience)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $model);
            $data = array_replace($model->only(['name', 'entity_type', 'type', 'segment_id', 'active']), $request->validated());
            abort_unless($data['entity_type'] === $model->entity_type && $data['type'] === $model->type, 409, 'Audience entity and membership types are immutable.');
            $this->validateSegment($data);
            if ($data['segment_id'] !== $model->segment_id) {
                $model->members()->delete();
                $data['refreshed_at'] = null;
            }
            $model->update($data);
            $this->audit->record('audience_updated', $model);

            return ApiResponse::success($model->fresh());
        });
    }

    public function members(Request $request, int $audience): JsonResponse
    {
        $model = Audience::findOrFail($audience);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->members()->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function changeMembers(Request $request, int $audience): JsonResponse
    {
        $model = Audience::findOrFail($audience);
        $this->authorize('update', $model);
        $data = $request->validate(['entity_ids' => ['required', 'array', 'between:1,500'], 'entity_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);

        return ApiResponse::success($this->audiences->members($model, $data['entity_ids'], $request->isMethod('delete')));
    }

    public function refresh(int $audience): JsonResponse
    {
        $model = Audience::findOrFail($audience);
        $this->authorize('update', $model);
        ProcessMarketingJob::dispatch((int) $model->tenant_id, 'audience', (int) $model->id)->afterCommit();

        return ApiResponse::success(['queued' => true, 'audience_id' => $model->id], [], 202);
    }

    public function destroy(int $audience): JsonResponse
    {
        $model = Audience::findOrFail($audience);
        $this->authorize('delete', $model);
        abort_if(Campaign::where('audience_id', $model->id)->exists(), 409, 'A campaign still references this audience.');
        $model->delete();
        $this->audit->record('audience_deleted', $model);

        return ApiResponse::success(['deleted' => true]);
    }

    private function validateSegment(array $data): void
    {
        $segment = isset($data['segment_id']) ? Segment::find($data['segment_id']) : null;
        if (($data['type'] === 'dynamic' && ($segment === null || ! $segment->active || $segment->entity_type !== $data['entity_type'])) ||
            ($data['type'] === 'static' && isset($data['segment_id']))) {
            throw ValidationException::withMessages(['segment_id' => 'Dynamic audiences require an active segment of the same entity type; static audiences cannot reference one.']);
        }
    }
}
