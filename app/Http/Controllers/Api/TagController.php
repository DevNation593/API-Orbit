<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TagRequest;
use App\Models\Tag;
use App\Services\DuplicateNormalizer;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantRelationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TagController extends Controller
{
    public function __construct(
        private readonly DuplicateNormalizer $normalizer,
        private readonly TenantRelationResolver $relations,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'tags.view');
        $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['required_with:entity_id', 'string', 'max:80'],
            'entity_id' => ['required_with:entity_type', 'integer', 'min:1'],
        ]);
        $allowedTypes = $this->allowedEntityTypes($request);
        $query = Tag::query()->with('creator:id,name')->withCount([
            'assignments' => fn (Builder $assignments) => $assignments->whereIn('taggable_type', $allowedTypes),
        ]);
        if ($request->filled('search')) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim((string) $request->input('search'))));
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }
        if ($request->filled('entity_type')) {
            $type = $this->relations->canonicalEntityType(
                (string) $request->input('entity_type'), (int) $request->input('entity_id'),
            );
            $this->assertEntityPermission($request, $type);
            $query->whereHas('assignments', fn (Builder $assignments) => $assignments
                ->where('taggable_type', $type)->where('taggable_id', $request->integer('entity_id')));
        }

        return ApiResponse::paginated($query->orderBy('name')
            ->paginate(ApiResponse::perPage($request->input('per_page', 50)), ['*'], 'page')->withQueryString());
    }

    public function store(TagRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'tags.manage');
        $data = $request->validated();
        $data['name'] = trim($data['name']);
        $data['normalized_name'] = $this->normalizedName($data['name']);
        $this->assertUnique($data['normalized_name']);
        $tag = Tag::create(array_merge($data, ['created_by' => $request->user()->id]));
        $this->audit->record('create', $tag, newValues: $tag->getAttributes());

        return ApiResponse::success($tag->load('creator:id,name')->loadCount('assignments'), [], 201);
    }

    public function show(Request $request, int $tag): JsonResponse
    {
        $this->authorizePermission($request, 'tags.view');

        return ApiResponse::success(Tag::query()->with('creator:id,name')->withCount([
            'assignments' => fn (Builder $assignments) => $assignments
                ->whereIn('taggable_type', $this->allowedEntityTypes($request)),
        ])->findOrFail($tag));
    }

    public function update(TagRequest $request, int $tag): JsonResponse
    {
        $this->authorizePermission($request, 'tags.manage');
        $model = Tag::findOrFail($tag);
        $data = $request->validated();
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
            $data['normalized_name'] = $this->normalizedName($data['name']);
            $this->assertUnique($data['normalized_name'], $model->id);
        }
        $old = $model->getAttributes();
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $model->getAttributes());

        return ApiResponse::success($model->fresh()->load('creator:id,name')->loadCount([
            'assignments' => fn (Builder $assignments) => $assignments
                ->whereIn('taggable_type', $this->allowedEntityTypes($request)),
        ]));
    }

    public function destroy(Request $request, int $tag): JsonResponse
    {
        $this->authorizePermission($request, 'tags.manage');
        $model = Tag::findOrFail($tag);
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function normalizedName(string $name): string
    {
        $normalized = $this->normalizer->text($name);
        if ($normalized === null) {
            throw ValidationException::withMessages(['name' => 'The tag name must contain letters or numbers.']);
        }

        return $normalized;
    }

    private function assertUnique(string $normalized, ?int $ignore = null): void
    {
        $exists = Tag::query()->where('normalized_name', $normalized)
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A tag with this name already exists.']);
        }
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403, 'You do not have permission to manage tags.');
    }

    private function assertEntityPermission(Request $request, string $type): void
    {
        $permission = match ($type) {
            'contact' => 'contacts.view', 'organization' => 'organizations.view',
            'lead' => 'leads.view', 'deal' => 'deals.view', 'task' => 'tasks.view',
            'activity' => 'activities.view', 'file' => 'files.view',
            'entity_record' => 'custom_entities.view',
            'conversation' => 'conversations.view',
        };
        abort_unless($request->user()->hasPermission($permission), 403, 'You cannot view tags for this resource.');
    }

    /** @return array<int, string> */
    private function allowedEntityTypes(Request $request): array
    {
        return collect([
            'contact' => 'contacts.view', 'organization' => 'organizations.view',
            'lead' => 'leads.view', 'deal' => 'deals.view', 'task' => 'tasks.view',
            'activity' => 'activities.view', 'file' => 'files.view',
            'entity_record' => 'custom_entities.view',
            'conversation' => 'conversations.view',
        ])->filter(fn (string $permission): bool => $request->user()->hasPermission($permission))->keys()->all();
    }
}
