<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SequenceControlRequest;
use App\Http\Requests\SequenceEnrollmentRequest;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Services\SequenceEnrollmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SequenceEnrollmentController extends Controller
{
    public function __construct(private readonly SequenceEnrollmentService $enrollments) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sequences.view'), 403);
        $query = SequenceEnrollment::query()->with($this->relations())
            ->when($request->filled('sequence_id'), fn ($builder) => $builder->where('sequence_id', $request->integer('sequence_id')))
            ->when($request->filled('lead_id'), fn ($builder) => $builder->where('lead_id', $request->integer('lead_id')))
            ->when($request->filled('contact_id'), fn ($builder) => $builder->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->lower()->value()))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(SequenceEnrollmentRequest $request, int $sequence): JsonResponse
    {
        $model = Sequence::findOrFail($sequence);
        $this->authorize('enroll', $model);

        return ApiResponse::success(
            $this->enrollments->enroll($model, $request->validated(), (int) $request->user()->id),
            [],
            201,
        );
    }

    public function show(Request $request, int $enrollment): JsonResponse
    {
        $model = $this->model($request, $enrollment);

        return ApiResponse::success($model->load($this->relations())->loadCount('executions'));
    }

    public function pause(SequenceControlRequest $request, int $enrollment): JsonResponse
    {
        return ApiResponse::success($this->enrollments->pause(
            $this->model($request, $enrollment, true),
            $request->validated('reason'),
        ));
    }

    public function resume(SequenceControlRequest $request, int $enrollment): JsonResponse
    {
        return ApiResponse::success($this->enrollments->resume($this->model($request, $enrollment, true)));
    }

    public function stop(SequenceControlRequest $request, int $enrollment): JsonResponse
    {
        return ApiResponse::success($this->enrollments->stop(
            $this->model($request, $enrollment, true),
            $request->validated('reason') ?: 'manual_stop',
        ));
    }

    public function executions(Request $request, int $enrollment): JsonResponse
    {
        $model = $this->model($request, $enrollment);
        $query = $model->executions()->with('step:id,position,type')->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    private function model(Request $request, int $id, bool $manage = false): SequenceEnrollment
    {
        abort_unless($request->user()->hasPermission($manage ? 'sequences.enroll' : 'sequences.view'), 403);

        return SequenceEnrollment::findOrFail($id);
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'sequence:id,name,status',
            'lead:id,first_name,last_name,email,phone',
            'contact:id,first_name,last_name,email,phone',
            'enrolledBy:id,name,email',
            'sender:id,name,email',
        ];
    }
}
