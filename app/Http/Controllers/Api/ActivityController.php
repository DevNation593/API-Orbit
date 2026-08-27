<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivityRequest;
use App\Models\Activity;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use App\Support\TenantRelationResolver;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function __construct(
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly TenantRelationResolver $relations,
    ) {}

    public function index(ActivityRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);
        $query = Activity::query()->with('user:id,name');
        $query = $this->filters->apply($query, $request, [
            'type' => 'activities.type', 'subject' => 'activities.subject', 'user_id' => 'activities.user_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(ActivityRequest $request): JsonResponse
    {
        $this->authorize('create', Activity::class);
        $data = $request->validated();
        $data['activityable_type'] = $this->relations->canonicalMorphType(
            $data['activityable_type'] ?? null,
            $data['activityable_id'] ?? null,
        );
        $data['user_id'] ??= $request->user()->id;
        $activity = Activity::create($data);
        $this->audit->record('create', $activity, newValues: $activity->getAttributes());

        return ApiResponse::success($activity->load('user:id,name'), [], 201);
    }
}
