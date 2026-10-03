<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavedViewRequest;
use App\Models\SavedView;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavedViewController extends Controller
{
    private const ENTITY_PERMISSIONS = [
        'contacts' => 'contacts.view', 'companies' => 'organizations.view',
        'leads' => 'leads.view', 'opportunities' => 'deals.view', 'tasks' => 'tasks.view',
        'activities' => 'activities.view', 'documents' => 'files.view',
        'custom_objects' => 'custom_entities.view',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permission($request->user(), 'saved_views.view');
        $request->validate([
            'entity_type' => ['nullable', 'string', Rule::in([
                ...array_keys(self::ENTITY_PERMISSIONS), 'organizations', 'deals', 'files',
            ])],
        ]);
        $roleId = $this->roleId($request->user());
        $allowedTypes = collect(self::ENTITY_PERMISSIONS)->filter(
            fn (string $permission): bool => $request->user()->hasPermission($permission),
        )->keys();
        $query = SavedView::query()->with(['owner:id,name', 'sharedWithRole:id,name', 'filters'])
            ->whereIn('entity_type', $allowedTypes)
            ->where(function (Builder $visible) use ($request, $roleId): void {
                $visible->where('user_id', $request->user()->id)->orWhere('visibility', 'tenant');
                if ($roleId !== null) {
                    $visible->orWhere(fn (Builder $team) => $team
                        ->where('visibility', 'team')->where('shared_with_role_id', $roleId));
                }
            });
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $this->canonicalType((string) $request->input('entity_type')));
        }

        return ApiResponse::paginated($query->orderByDesc('is_default')->orderBy('name')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(SavedViewRequest $request): JsonResponse
    {
        $this->permission($request->user(), 'saved_views.manage');
        $data = $this->prepare($request->validated(), $request->user());
        $view = $this->database->transaction(function () use ($data, $request): SavedView {
            $this->assertUnique($request->user(), $data['entity_type'], $data['name']);
            if ($data['is_default'] ?? false) {
                $this->clearDefaults($request->user(), $data['entity_type']);
            }
            $filters = $data['filters'] ?? [];
            unset($data['filters']);
            $view = SavedView::create(array_merge($data, ['user_id' => $request->user()->id]));
            $this->syncFilters($view, $filters);
            $this->audit->record('create', $view, newValues: $view->getAttributes());

            return $view;
        });

        return ApiResponse::success($view->load(['owner:id,name', 'sharedWithRole:id,name', 'filters']), [], 201);
    }

    public function show(Request $request, int $savedView): JsonResponse
    {
        $this->permission($request->user(), 'saved_views.view');
        $view = SavedView::query()->with(['owner:id,name', 'sharedWithRole:id,name', 'filters'])->findOrFail($savedView);
        $this->assertVisible($view, $request->user());
        $this->assertEntityPermission($request->user(), $view->entity_type);

        return ApiResponse::success($view);
    }

    public function update(SavedViewRequest $request, int $savedView): JsonResponse
    {
        $this->permission($request->user(), 'saved_views.manage');
        $view = SavedView::query()->with('owner')->findOrFail($savedView);
        $this->assertModifiable($view, $request->user());
        $data = $this->prepare($request->validated(), $request->user(), $view);
        $this->database->transaction(function () use ($view, $data): void {
            $entityType = $data['entity_type'] ?? $view->entity_type;
            $name = $data['name'] ?? $view->name;
            $this->assertUnique($view->owner, $entityType, $name, $view->id);
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefaults($view->owner, $entityType, $view->id);
            }
            $filters = $data['filters'] ?? null;
            unset($data['filters']);
            $old = $view->getAttributes();
            $view->update($data);
            if ($filters !== null) {
                $this->syncFilters($view, $filters);
            }
            $this->audit->record('update', $view, oldValues: $old, newValues: $view->getAttributes());
        });

        return ApiResponse::success($view->fresh()->load(['owner:id,name', 'sharedWithRole:id,name', 'filters']));
    }

    public function destroy(Request $request, int $savedView): JsonResponse
    {
        $this->permission($request->user(), 'saved_views.manage');
        $view = SavedView::findOrFail($savedView);
        $this->assertModifiable($view, $request->user());
        $old = $view->getAttributes();
        $view->delete();
        $this->audit->record('delete', $view, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, User $user, ?SavedView $view = null): array
    {
        $entityType = $this->canonicalType($data['entity_type'] ?? $view?->entity_type ?? '');
        $this->assertEntityPermission($user, $entityType);
        $data['entity_type'] = $entityType;
        $visibility = $data['visibility'] ?? $view?->visibility ?? 'private';
        $data['visibility'] = $visibility;
        if ($visibility === 'team') {
            $data['shared_with_role_id'] ??= $this->roleId($user);
            if ($data['shared_with_role_id'] === null) {
                throw ValidationException::withMessages(['shared_with_role_id' => 'A team view requires a tenant role.']);
            }
        } else {
            $data['shared_with_role_id'] = null;
        }

        return $data;
    }

    private function canonicalType(string $type): string
    {
        return match ($type) {
            'organizations' => 'companies', 'deals' => 'opportunities', 'files' => 'documents', default => $type,
        };
    }

    private function roleId(User $user): ?int
    {
        $id = $user->memberships()->where('tenant_id', app(TenantContext::class)->requireId())
            ->where('status', 'active')->value('role_id');

        return $id === null ? null : (int) $id;
    }

    private function assertVisible(SavedView $view, User $user): void
    {
        $visible = (int) $view->user_id === (int) $user->id || $view->visibility === 'tenant'
            || ($view->visibility === 'team' && (int) $view->shared_with_role_id === (int) $this->roleId($user));
        abort_unless($visible, 404, 'Saved view not found.');
    }

    private function assertModifiable(SavedView $view, User $user): void
    {
        abort_unless((int) $view->user_id === (int) $user->id || $user->hasPermission('settings.manage'), 403,
            'You cannot modify this saved view.');
    }

    private function assertEntityPermission(User $user, string $type): void
    {
        $permission = self::ENTITY_PERMISSIONS[$type] ?? null;
        if ($permission === null) {
            throw ValidationException::withMessages(['entity_type' => 'Unsupported saved view entity type.']);
        }
        abort_unless($user->hasPermission($permission), 403, 'You cannot create or access views for this resource.');
    }

    private function permission(User $user, string $permission): void
    {
        abort_unless($user->hasPermission($permission), 403, 'You do not have permission to manage saved views.');
    }

    private function assertUnique(User $owner, string $type, string $name, ?int $ignore = null): void
    {
        $exists = SavedView::query()->where('user_id', $owner->id)->where('entity_type', $type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A saved view with this name already exists for the resource.']);
        }
    }

    private function clearDefaults(User $owner, string $type, ?int $except = null): void
    {
        SavedView::query()->where('user_id', $owner->id)->where('entity_type', $type)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    /** @param array<int, array<string, mixed>> $filters */
    private function syncFilters(SavedView $view, array $filters): void
    {
        $view->filters()->delete();
        foreach (array_values($filters) as $position => $filter) {
            $view->filters()->create([
                'field' => $filter['field'], 'operator' => $filter['operator'],
                'value' => $filter['value'], 'position' => $position,
            ]);
        }
    }
}
