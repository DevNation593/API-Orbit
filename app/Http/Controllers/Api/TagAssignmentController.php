<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TagAssignmentRequest;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantRelationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagAssignmentController extends Controller
{
    public function __construct(
        private readonly TenantRelationResolver $relations,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request, int $tag): JsonResponse
    {
        $this->permission($request, 'tags.view');
        $model = Tag::findOrFail($tag);
        $assignments = $model->assignments()->whereIn('taggable_type', $this->allowedEntityTypes($request))
            ->with('assigner:id,name')->latest('created_at')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString();

        return ApiResponse::paginated($assignments);
    }

    public function store(TagAssignmentRequest $request, int $tag): JsonResponse
    {
        $this->permission($request, 'tags.manage');
        Tag::findOrFail($tag);
        $data = $request->validated();
        $type = $this->relations->canonicalEntityType($data['entity_type'], $data['entity_id']);
        $this->entityPermission($request, $type);
        $assignment = TagAssignment::firstOrCreate([
            'tag_id' => $tag,
            'taggable_type' => $type,
            'taggable_id' => (int) $data['entity_id'],
        ], ['assigned_by' => $request->user()->id]);
        if ($assignment->wasRecentlyCreated) {
            $this->audit->record('create', $assignment, newValues: $assignment->getAttributes());
        }

        return ApiResponse::success(
            $assignment->load(['tag:id,name,color', 'assigner:id,name']),
            ['created' => $assignment->wasRecentlyCreated],
            $assignment->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, int $tag, int $assignment): JsonResponse
    {
        $this->permission($request, 'tags.manage');
        Tag::findOrFail($tag);
        $model = TagAssignment::query()->where('tag_id', $tag)->findOrFail($assignment);
        $this->entityPermission($request, $model->taggable_type);
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function permission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403, 'You do not have permission to manage tags.');
    }

    private function entityPermission(Request $request, string $type): void
    {
        $permission = match ($type) {
            'contact' => 'contacts.view', 'organization' => 'organizations.view',
            'lead' => 'leads.view', 'deal' => 'deals.view', 'task' => 'tasks.view',
            'activity' => 'activities.view', 'file' => 'files.view',
            'entity_record' => 'custom_entities.view',
            'conversation' => 'conversations.view',
        };
        abort_unless($request->user()->hasPermission($permission), 403, 'You cannot tag this resource.');
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
