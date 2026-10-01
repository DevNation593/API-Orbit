<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScoringEventRequest;
use App\Models\Lead;
use App\Services\LeadScoringService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadScoreController extends Controller
{
    public function __construct(private readonly LeadScoringService $scoring) {}

    public function index(Request $request, int $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('lead_scoring.view'), 403);
        $model = Lead::findOrFail($lead);
        $this->authorize('view', $model);

        return ApiResponse::success($model->scores()->with('model:id,name,is_default')->orderByDesc('calculated_at')->get());
    }

    public function recalculate(Request $request, int $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('lead_scoring.manage'), 403);
        $model = Lead::findOrFail($lead);
        $this->authorize('update', $model);

        return ApiResponse::success($this->scoring->recalculate($model));
    }

    public function event(ScoringEventRequest $request, int $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('lead_scoring.manage'), 403);
        $model = Lead::findOrFail($lead);
        $this->authorize('update', $model);
        $data = $request->validated();

        return ApiResponse::success($this->scoring->recordEvent(
            $model,
            $data['event_type'],
            $data['event_key'],
            $data['metadata'] ?? [],
        ));
    }
}
