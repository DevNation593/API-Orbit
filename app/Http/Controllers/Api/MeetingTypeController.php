<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingTypeRequest;
use App\Models\MeetingType;
use App\Services\MeetingTypeService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MeetingTypeController extends Controller
{
    public function __construct(
        private readonly MeetingTypeService $types,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MeetingType::class);
        $query = MeetingType::query()->with('host:id,name,email')->withCount([
            'availabilityRules', 'bookings',
            'bookings as upcoming_bookings_count' => fn ($builder) => $builder
                ->where('status', 'confirmed')->where('starts_at', '>=', now()),
        ])->when($request->has('active'), fn ($builder) => $builder->where('active', $request->boolean('active')))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(MeetingTypeRequest $request): JsonResponse
    {
        $this->authorize('create', MeetingType::class);

        return ApiResponse::success($this->types->create($request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $meetingType): JsonResponse
    {
        $model = MeetingType::query()->with([
            'host:id,name,email', 'creator:id,name,email',
            'availabilityRules.user:id,name,email', 'exclusions.user:id,name,email',
        ])->withCount(['bookings', 'availabilityRules'])->findOrFail($meetingType);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(MeetingTypeRequest $request, int $meetingType): JsonResponse
    {
        $model = MeetingType::findOrFail($meetingType);
        $this->authorize('update', $model);

        return ApiResponse::success($this->types->update($model, $request->validated()));
    }

    public function destroy(int $meetingType): JsonResponse
    {
        $model = MeetingType::findOrFail($meetingType);
        $this->authorize('delete', $model);
        abort_if($model->bookings()->where('status', 'confirmed')->where('starts_at', '>=', now())->exists(), 409,
            'A meeting type with future bookings cannot be deleted; deactivate it instead.');
        $old = $model->getAttributes();
        $model->update(['active' => false]);
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    public function rotatePublicId(int $meetingType): JsonResponse
    {
        $model = MeetingType::findOrFail($meetingType);
        $this->authorize('update', $model);
        $old = $model->public_id;
        $model->update(['public_id' => (string) Str::uuid()]);
        $this->audit->record('meeting_public_id_rotated', $model,
            oldValues: ['public_id' => $old], newValues: ['public_id' => $model->public_id]);

        return ApiResponse::success(['public_id' => $model->public_id]);
    }
}
