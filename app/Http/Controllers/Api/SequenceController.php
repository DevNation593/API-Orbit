<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SequenceRequest;
use App\Models\Sequence;
use App\Services\SequenceDefinitionService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SequenceController extends Controller
{
    public function __construct(
        private readonly SequenceDefinitionService $definitions,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sequence::class);
        $query = Sequence::query()->with('creator:id,name,email')->withCount(['steps', 'enrollments'])
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->lower()->value()))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(SequenceRequest $request): JsonResponse
    {
        $this->authorize('create', Sequence::class);

        return ApiResponse::success($this->definitions->create($request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $sequence): JsonResponse
    {
        $model = Sequence::query()->with(['steps', 'creator:id,name,email'])->withCount([
            'enrollments',
            'enrollments as active_enrollments_count' => fn ($query) => $query->whereIn('status', ['active', 'paused']),
        ])->findOrFail($sequence);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(SequenceRequest $request, int $sequence): JsonResponse
    {
        $model = Sequence::findOrFail($sequence);
        $this->authorize('update', $model);

        return ApiResponse::success($this->definitions->update($model, $request->validated()));
    }

    public function destroy(int $sequence): JsonResponse
    {
        $model = Sequence::findOrFail($sequence);
        $this->authorize('delete', $model);
        abort_if($model->enrollments()->whereIn('status', ['active', 'paused'])->exists(), 409,
            'A sequence with active or paused enrollments cannot be deleted.');
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
