<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use App\Services\CampaignService;
use App\Services\CampaignTelemetryService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns, private readonly CampaignTelemetryService $telemetry) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(Campaign::with('emailCampaign')->withCount('members')->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function store(CampaignRequest $request): JsonResponse
    {
        return ApiResponse::success($this->campaigns->save($request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $campaign): JsonResponse
    {
        $model = Campaign::with(['audience', 'emailCampaign'])->withCount('members')->findOrFail($campaign);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(CampaignRequest $request, int $campaign): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('update', $model);
        $data = array_replace($model->only(['name', 'description', 'audience_id', 'sender_user_id', 'currency', 'cost', 'scheduled_at']),
            ['email' => $model->emailCampaign->only(['inbox_channel_id', 'subject', 'body', 'body_html'])], $request->validated());

        return ApiResponse::success($this->campaigns->save($data, (int) $request->user()->id, $model));
    }

    public function transition(int $campaign, string $action): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('send', $model);

        return ApiResponse::success($this->campaigns->transition($model, $action), [], 202);
    }

    public function members(Request $request, int $campaign): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->members()->orderBy('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function events(Request $request, int $campaign): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->events()->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function recordEvent(Request $request, int $campaign): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('update', $model);
        $data = $request->validate(['campaign_member_id' => ['required', 'integer', 'min:1']]);
        $member = $model->members()->findOrFail($data['campaign_member_id']);

        return ApiResponse::success($this->telemetry->record($model, $member, $request->all()), [], 201);
    }

    public function metrics(int $campaign): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('view', $model);

        return ApiResponse::success($this->telemetry->metrics($model));
    }

    public function destroy(int $campaign): JsonResponse
    {
        $model = Campaign::findOrFail($campaign);
        $this->authorize('delete', $model);
        abort_unless($model->status === 'draft', 409, 'Only drafts can be deleted; cancel launched campaigns to preserve history.');
        $model->delete();
        app(AuditService::class)->record('campaign_deleted', $model);

        return ApiResponse::success(['deleted' => true]);
    }
}
