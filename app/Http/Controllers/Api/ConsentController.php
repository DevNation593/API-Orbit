<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentLink;
use App\Models\ConsentRecord;
use App\Services\ConsentService;
use App\Services\SegmentEngine;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consent, private readonly SegmentEngine $entities) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->subjectData($request);
        $this->entities->subject($data['entity_type'], (int) $data['entity_id']);

        return ApiResponse::paginated(ConsentRecord::where('entity_type', $data['entity_type'])->where('entity_id', $data['entity_id'])
            ->orderByDesc('id')->paginate(ApiResponse::perPage($request->input('per_page'))));
    }

    public function preferences(Request $request): JsonResponse
    {
        $data = $this->subjectData($request);
        $subject = $this->entities->subject($data['entity_type'], (int) $data['entity_id']);

        return ApiResponse::success($this->consent->preferences($subject));
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success($this->consent->record($request->all(), $request->ip(), (int) $request->user()->id), [], 201);
    }

    public function link(Request $request): JsonResponse
    {
        $data = $this->subjectData($request);

        return ApiResponse::success($this->consent->issueLink($data['entity_type'], (int) $data['entity_id']), [], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function revokeLink(int $consentLink): JsonResponse
    {
        $link = ConsentLink::findOrFail($consentLink);
        $this->authorize('update', $link);
        $link->update(['revoked_at' => $link->revoked_at ?? now()]);
        app(AuditService::class)->record('consent_link_revoked', $link);

        return ApiResponse::success(['revoked' => true]);
    }

    private function subjectData(Request $request): array
    {
        return $request->validate([
            'entity_type' => ['required', Rule::in(['contacts', 'leads'])],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);
    }
}
