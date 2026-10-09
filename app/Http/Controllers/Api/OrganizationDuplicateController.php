<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MergeOrganizationRequest;
use App\Http\Requests\OrganizationDuplicateCheckRequest;
use App\Models\Organization;
use App\Services\DuplicateDetectionService;
use App\Services\RecordMergeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class OrganizationDuplicateController extends Controller
{
    public function __construct(
        private readonly DuplicateDetectionService $duplicates,
        private readonly RecordMergeService $merges,
    ) {}

    public function check(OrganizationDuplicateCheckRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);
        $matches = $this->duplicates->organizations($request->validated());

        return ApiResponse::success($matches, ['count' => $matches->count()]);
    }

    public function merge(MergeOrganizationRequest $request, int $organization): JsonResponse
    {
        abort_unless($request->user()->hasPermission('duplicates.manage'), 403, 'You do not have permission to merge duplicate records.');
        $target = Organization::findOrFail($organization);
        $source = Organization::findOrFail((int) $request->validated('duplicate_id'));
        $this->authorize('update', $target);
        $this->authorize('delete', $source);

        return ApiResponse::success($this->merges->mergeOrganizations(
            $target,
            $source,
            $request->validated('field_overrides', []),
        ));
    }
}
