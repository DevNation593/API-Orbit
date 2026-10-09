<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicFormSubmissionRequest;
use App\Models\Form;
use App\Services\FormSubmissionService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class PublicFormController extends Controller
{
    public function __construct(
        private readonly FormSubmissionService $submissions,
        private readonly TenantContext $context,
    ) {}

    public function show(string $publicId): JsonResponse
    {
        $form = $this->form($publicId);

        return $this->withinTenant($form, fn () => ApiResponse::success($this->submissions->publicDefinition($form)));
    }

    public function submit(PublicFormSubmissionRequest $request, string $publicId): JsonResponse
    {
        $form = $this->form($publicId);

        return $this->withinTenant($form, function () use ($form, $request): JsonResponse {
            $result = $this->submissions->submit($form, $request->validated(), $request);
            $rejected = $result['rejected'];

            return ApiResponse::success([
                'submission_id' => $result['submission']->public_id,
                'accepted' => true,
                'message' => $rejected ? ($form->success_message ?: 'Submission received.') : ($form->success_message ?: 'Submission received.'),
                'redirect_url' => $rejected ? null : $form->redirect_url,
            ], ['replayed' => $result['replayed']], $rejected ? 202 : ($result['replayed'] ? 200 : 201));
        });
    }

    private function form(string $publicId): Form
    {
        return Form::withoutGlobalScope('tenant')->publiclyAvailable()
            ->with(['fields' => fn ($query) => $query->where('active', true), 'fields.fieldDefinition'])
            ->where('public_id', $publicId)->firstOrFail();
    }

    private function withinTenant(Form $form, callable $callback): JsonResponse
    {
        $previous = $this->context->id();
        try {
            $this->context->set((int) $form->tenant_id);

            return $callback();
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
