<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InboxRequest;
use App\Models\Inbox;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InboxController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Inbox::class);
        $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'channel' => ['sometimes', 'string', 'max:30'],
        ]);
        $query = Inbox::query()->with([
            'channels', 'defaultAssignee:id,name', 'defaultRole:id,name', 'creator:id,name',
        ])->withCount('conversations');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('channel')) {
            $query->whereHas('channels', fn (Builder $channels) => $channels
                ->where('channel', strtolower((string) $request->input('channel'))));
        }

        return ApiResponse::paginated($query->orderBy('name')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(InboxRequest $request): JsonResponse
    {
        $this->authorize('create', Inbox::class);
        $data = $request->validated();
        $channels = $data['channels'] ?? [];
        unset($data['channels']);
        $this->assertUnique($data['name']);

        $inbox = $this->database->transaction(function () use ($data, $channels, $request): Inbox {
            $inbox = Inbox::create(array_merge($data, ['created_by' => $request->user()->id]));
            foreach ($channels as $channel) {
                $inbox->channels()->create($channel);
            }
            $this->audit->record('create', $inbox, newValues: [
                'name' => $inbox->name, 'status' => $inbox->status, 'channels' => count($channels),
            ]);

            return $inbox;
        });

        return ApiResponse::success($this->load($inbox), [], 201);
    }

    public function show(Request $request, int $inbox): JsonResponse
    {
        $model = Inbox::findOrFail($inbox);
        $this->authorize('view', $model);

        return ApiResponse::success($this->load($model));
    }

    public function update(InboxRequest $request, int $inbox): JsonResponse
    {
        $model = Inbox::findOrFail($inbox);
        $this->authorize('update', $model);
        $data = $request->validated();
        unset($data['channels']);
        if (isset($data['name'])) {
            $this->assertUnique($data['name'], $model->id);
        }
        $old = $model->getAttributes();
        $model->update($data);
        $this->audit->record('update', $model, oldValues: $old, newValues: $model->getAttributes());

        return ApiResponse::success($this->load($model->fresh()));
    }

    private function load(Inbox $inbox): Inbox
    {
        return $inbox->load([
            'channels.integration:id,provider,name,status',
            'defaultAssignee:id,name', 'defaultRole:id,name', 'creator:id,name',
        ])->loadCount('conversations');
    }

    private function assertUnique(string $name, ?int $ignore = null): void
    {
        $exists = Inbox::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'An inbox with this name already exists.']);
        }
    }
}
