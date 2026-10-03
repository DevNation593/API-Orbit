<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FormDefinitionRequest;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\FormBuilderService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FormController extends Controller
{
    public function __construct(
        private readonly FormBuilderService $builder,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Form::class);
        $query = Form::query()->withCount(['fields', 'submissions'])->with('creator:id,name')
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->lower()->value()))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(FormDefinitionRequest $request): JsonResponse
    {
        $this->authorize('create', Form::class);

        return ApiResponse::success($this->builder->create($request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $form = Form::with(['fields.fieldDefinition', 'creator:id,name'])->withCount('submissions')->findOrFail($id);
        $this->authorize('view', $form);

        return ApiResponse::success($form);
    }

    public function update(FormDefinitionRequest $request, int $id): JsonResponse
    {
        $form = Form::findOrFail($id);
        $this->authorize('update', $form);

        return ApiResponse::success($this->builder->update($form, $request->validated()));
    }

    public function destroy(int $id): JsonResponse
    {
        $form = Form::findOrFail($id);
        $this->authorize('delete', $form);
        $old = $form->getAttributes();
        $form->delete();
        $this->audit->record('delete', $form, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    public function rotatePublicId(int $id): JsonResponse
    {
        $form = Form::findOrFail($id);
        $this->authorize('update', $form);
        $old = $form->public_id;
        $form->update(['public_id' => (string) Str::uuid()]);
        $this->audit->record('form_public_id_rotated', $form, oldValues: ['public_id' => $old], newValues: ['public_id' => $form->public_id]);

        return ApiResponse::success(['public_id' => $form->public_id]);
    }

    public function submissions(Request $request, int $id): JsonResponse
    {
        $form = Form::findOrFail($id);
        $this->authorize('viewSubmissions', $form);
        $query = $form->submissions()->with(['lead:id,first_name,last_name,email', 'contact:id,first_name,last_name,email'])
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->lower()->value()))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function submission(int $id, int $submission): JsonResponse
    {
        $form = Form::findOrFail($id);
        $this->authorize('viewSubmissions', $form);
        $model = FormSubmission::with(['lead:id,first_name,last_name,email', 'contact:id,first_name,last_name,email'])
            ->where('form_id', $form->id)->findOrFail($submission);

        return ApiResponse::success($model);
    }
}
