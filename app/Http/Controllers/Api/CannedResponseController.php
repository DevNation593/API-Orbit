<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CannedResponseRequest;
use App\Models\CannedResponse;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CannedResponseController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->viewPermission($request);
        $request->validate([
            'channel' => ['sometimes', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = CannedResponse::query()->with('creator:id,name');
        if ($request->filled('channel')) {
            $query->where(fn (Builder $channels) => $channels->whereNull('channel')
                ->orWhere('channel', strtolower((string) $request->input('channel'))));
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if ($request->filled('search')) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim((string) $request->input('search'))));
            $query->where(fn (Builder $search) => $search
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", ['%'.$term.'%'])
                ->orWhereRaw("LOWER(shortcut) LIKE ? ESCAPE '!'", ['%'.$term.'%']));
        }

        return ApiResponse::paginated($query->orderBy('shortcut')
            ->paginate(ApiResponse::perPage($request->input('per_page', 50), 50))->withQueryString());
    }

    public function store(CannedResponseRequest $request): JsonResponse
    {
        $this->managePermission($request);
        $data = $request->validated();
        $this->assertUnique($data['shortcut']);
        $model = CannedResponse::create(array_merge($data, ['created_by' => $request->user()->id]));
        $this->audit->record('create', $model, newValues: $model->getAttributes());

        return ApiResponse::success($model->load('creator:id,name'), [], 201);
    }

    public function update(CannedResponseRequest $request, int $cannedResponse): JsonResponse
    {
        $this->managePermission($request);
        $model = CannedResponse::findOrFail($cannedResponse);
        $data = $request->validated();
        if (isset($data['shortcut'])) {
            $this->assertUnique($data['shortcut'], $model->id);
        }
        $old = $model->getAttributes();
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $model->getAttributes());

        return ApiResponse::success($model->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, int $cannedResponse): JsonResponse
    {
        $this->managePermission($request);
        $model = CannedResponse::findOrFail($cannedResponse);
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function viewPermission(Request $request): void
    {
        abort_unless($request->user()->hasPermission('conversations.view'), 403, 'You cannot view canned responses.');
    }

    private function managePermission(Request $request): void
    {
        abort_unless($request->user()->hasPermission('inboxes.manage'), 403, 'You cannot manage canned responses.');
    }

    private function assertUnique(string $shortcut, ?int $ignore = null): void
    {
        $exists = CannedResponse::query()->whereRaw('LOWER(shortcut) = ?', [mb_strtolower($shortcut)])
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['shortcut' => 'This canned response shortcut is already in use.']);
        }
    }
}
