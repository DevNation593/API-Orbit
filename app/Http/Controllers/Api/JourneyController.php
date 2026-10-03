<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMarketingJob;
use App\Models\Journey;
use App\Services\JourneyService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JourneyController extends Controller
{
    public function __construct(private readonly JourneyService $journeys, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(Journey::withCount(['versions', 'enrollments'])->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:10000'],
            'entity_type' => ['required', Rule::in(['contacts', 'leads'])], 'graph' => ['required', 'array'],
        ]);

        return DB::transaction(function () use ($data, $request): JsonResponse {
            $graph = $data['graph'];
            unset($data['graph']);
            $model = Journey::create($data + ['created_by' => $request->user()->id]);
            $this->journeys->version($model, $graph);
            $this->audit->record('journey_created', $model);

            return ApiResponse::success($model->fresh(['versions']), [], 201);
        });
    }

    public function show(int $journey): JsonResponse
    {
        $model = Journey::with('versions')->withCount('enrollments')->findOrFail($journey);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(Request $request, int $journey): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('update', $model);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:10000']]);
        $model->update($data);
        $this->audit->record('journey_updated', $model);

        return ApiResponse::success($model->fresh());
    }

    public function version(Request $request, int $journey): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('update', $model);
        $data = $request->validate(['graph' => ['required', 'array']]);

        return ApiResponse::success($this->journeys->version($model, $data['graph']), [], 201);
    }

    public function publish(Request $request, int $journey): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('update', $model);
        $data = $request->validate(['version_id' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->journeys->publish($model, (int) $data['version_id']));
    }

    public function status(Request $request, int $journey): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'archived'])]]);

        return DB::transaction(function () use ($journey, $data): JsonResponse {
            $model = Journey::whereKey($journey)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $model);
            abort_if($model->status === 'archived' || ($data['status'] === 'active' && $model->published_version === null), 409, 'This journey cannot be activated.');
            abort_if($data['status'] === 'archived' && $model->enrollments()->whereIn('status', ['active', 'paused'])->exists(), 409, 'Cancel active enrollments before archiving.');
            $model->update($data);
            $this->audit->record('journey_status_changed', $model, newValues: $data);
            if ($data['status'] === 'active') {
                ProcessMarketingJob::dispatch((int) $model->tenant_id, 'journey_resume', (int) $model->id)->afterCommit();
            }

            return ApiResponse::success($model->fresh());
        });
    }

    public function enrollments(Request $request, int $journey): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->enrollments()->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function enroll(Request $request, int $journey): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('enroll', $model);
        $data = $request->validate([
            'entity_id' => ['required', 'integer', 'min:1'], 'sender_user_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        return ApiResponse::success($this->journeys->enroll($model, $data), [], 201);
    }

    public function enrollment(int $journey, int $journeyEnrollment): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('view', $model);

        return ApiResponse::success($model->enrollments()->with(['version', 'executions'])->findOrFail($journeyEnrollment));
    }

    public function transition(int $journey, int $journeyEnrollment, string $action): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('enroll', $model);

        return ApiResponse::success($this->journeys->transition($model->enrollments()->findOrFail($journeyEnrollment), $action));
    }

    public function destroy(int $journey): JsonResponse
    {
        $model = Journey::findOrFail($journey);
        $this->authorize('delete', $model);
        abort_if($model->enrollments()->exists(), 409, 'Archive journeys with enrollment history.');
        $model->delete();
        $this->audit->record('journey_deleted', $model);

        return ApiResponse::success(['deleted' => true]);
    }
}
