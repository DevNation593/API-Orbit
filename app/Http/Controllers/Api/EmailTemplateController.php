<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Services\HtmlSanitizer;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailTemplateController extends Controller
{
    public function __construct(
        private readonly HtmlSanitizer $html,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permission($request, 'email_templates.view');
        $query = EmailTemplate::query()->with('creator:id,name');
        if ($request->has('active')) {
            $request->validate(['active' => ['boolean']]);
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::paginated($query->orderBy('name')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(EmailTemplateRequest $request): JsonResponse
    {
        $this->permission($request, 'email_templates.manage');
        $data = $request->validated();
        $this->assertUnique($data['name']);
        $data['body_html'] = $this->html->sanitize($data['body_html'] ?? null);
        $template = EmailTemplate::create(array_merge($data, ['created_by' => $request->user()->id]));
        $this->audit->record('create', $template, newValues: $this->auditValues($template));

        return ApiResponse::success($template->load('creator:id,name'), [], 201);
    }

    public function show(Request $request, int $template): JsonResponse
    {
        $this->permission($request, 'email_templates.view');

        return ApiResponse::success(EmailTemplate::with('creator:id,name')->findOrFail($template));
    }

    public function update(EmailTemplateRequest $request, int $template): JsonResponse
    {
        $this->permission($request, 'email_templates.manage');
        $model = EmailTemplate::findOrFail($template);
        $data = $request->validated();
        if (isset($data['name'])) {
            $this->assertUnique($data['name'], $model->id);
        }
        if (array_key_exists('body_html', $data)) {
            $data['body_html'] = $this->html->sanitize($data['body_html']);
        }
        $old = $this->auditValues($model);
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        $this->permission($request, 'email_templates.manage');
        $model = EmailTemplate::findOrFail($template);
        $old = $this->auditValues($model);
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function assertUnique(string $name, ?int $ignore = null): void
    {
        $exists = EmailTemplate::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'An email template with this name already exists.']);
        }
    }

    private function permission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403, 'You cannot manage email templates.');
    }

    /** @return array<string, mixed> */
    private function auditValues(EmailTemplate $template): array
    {
        return ['name' => $template->name, 'subject' => $template->subject, 'active' => $template->active];
    }
}
