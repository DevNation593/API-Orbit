<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConversationAssignmentRequest;
use App\Http\Requests\ConversationIndexRequest;
use App\Http\Requests\ConversationRequest;
use App\Http\Requests\ConversationStatusRequest;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\InboxChannel;
use App\Services\ConversationReadService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConversationReadService $reads,
        private readonly AuditService $audit,
    ) {}

    public function index(ConversationIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);
        $data = $request->validated();
        $query = Conversation::query()->with($this->relations($request));
        foreach ([
            'channel' => 'channel', 'status' => 'status', 'owner' => 'assigned_user_id',
            'team' => 'assigned_role_id', 'contact' => 'contact_id', 'inbox_id' => 'inbox_id',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $query->where($column, $data[$input]);
            }
        }
        if (isset($data['from'])) {
            $query->where('last_message_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->where('last_message_at', '<=', $data['to']);
        }
        if (array_key_exists('unread', $data)) {
            $this->reads->applyUnreadFilter($query, $request->user(), (bool) $data['unread']);
        }
        $paginator = $query->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString();
        $this->reads->decorate($paginator->getCollection(), $request->user());

        return ApiResponse::paginated($paginator);
    }

    public function store(ConversationRequest $request): JsonResponse
    {
        $this->authorize('create', Conversation::class);
        $data = $request->validated();
        $inbox = Inbox::findOrFail($data['inbox_id']);
        $channel = InboxChannel::findOrFail($data['inbox_channel_id']);
        if ((int) $channel->inbox_id !== (int) $inbox->id) {
            throw ValidationException::withMessages(['inbox_channel_id' => 'The channel does not belong to the selected inbox.']);
        }
        $data['channel'] = $channel->channel;
        $data['assigned_user_id'] ??= $inbox->default_assignee_id;
        $data['assigned_role_id'] ??= $inbox->default_role_id;

        $conversation = $this->database->transaction(function () use ($data, $request): Conversation {
            $conversation = Conversation::create($data);
            if ($conversation->contact_id !== null) {
                $conversation->participants()->create([
                    'contact_id' => $conversation->contact_id,
                    'type' => 'contact',
                    'role' => 'customer',
                ]);
            }
            if ($conversation->external_identifier !== null) {
                $conversation->participants()->create([
                    'type' => 'external',
                    'role' => 'customer',
                    'external_identifier' => $conversation->external_identifier,
                ]);
            }
            if ($conversation->assigned_user_id !== null || $conversation->assigned_role_id !== null) {
                $conversation->assignments()->create([
                    'assigned_user_id' => $conversation->assigned_user_id,
                    'assigned_role_id' => $conversation->assigned_role_id,
                    'assigned_by' => $request->user()->id,
                    'created_at' => now(),
                ]);
            }
            $this->audit->record('create', $conversation, newValues: $this->auditValues($conversation));

            return $conversation;
        });

        return ApiResponse::success($conversation->load($this->relations($request)), [], 201);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $model = Conversation::findOrFail($conversation);
        $this->authorize('view', $model);
        $model->load([...$this->relations($request), 'participants.user:id,name', 'participants.contact:id,first_name,last_name']);
        $this->reads->decorate(collect([$model]), $request->user());

        return ApiResponse::success($model);
    }

    public function assign(ConversationAssignmentRequest $request, int $conversation): JsonResponse
    {
        $model = Conversation::findOrFail($conversation);
        $this->authorize('assign', $model);
        $data = $request->validated();
        $old = $this->auditValues($model);
        $this->database->transaction(function () use ($model, $data, $request, $old): void {
            $model->update([
                'assigned_user_id' => $data['assigned_user_id'],
                'assigned_role_id' => $data['assigned_role_id'],
            ]);
            $model->assignments()->create([
                'assigned_user_id' => $data['assigned_user_id'],
                'assigned_role_id' => $data['assigned_role_id'],
                'assigned_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);
            $this->audit->record('assign', $model, oldValues: $old, newValues: $this->auditValues($model));
        });

        return ApiResponse::success($model->fresh()->load($this->relations($request)));
    }

    public function status(ConversationStatusRequest $request, int $conversation): JsonResponse
    {
        $model = Conversation::findOrFail($conversation);
        $this->authorize('changeStatus', $model);
        $old = $this->auditValues($model);
        $status = $request->validated('status');
        $model->update([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? now() : ($status === 'closed' ? $model->resolved_at : null),
            'closed_at' => $status === 'closed' ? now() : null,
        ]);
        $this->audit->record('status_change', $model, oldValues: $old, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh()->load($this->relations($request)));
    }

    public function markRead(Request $request, int $conversation): JsonResponse
    {
        $model = Conversation::findOrFail($conversation);
        $this->authorize('view', $model);
        $read = $this->reads->markRead($model, $request->user());

        return ApiResponse::success($read, ['unread_count' => 0]);
    }

    /** @return array<int, string> */
    private function relations(Request $request): array
    {
        $relations = [
            'inbox:id,name', 'inboxChannel:id,inbox_id,channel,name,address,status',
            'contact:id,first_name,last_name,email,phone', 'assignee:id,name', 'assignedRole:id,name',
        ];
        if ($request->user()->hasPermission('tags.view')) {
            $relations[] = 'tags:id,name,color';
        }

        return $relations;
    }

    /** @return array<string, mixed> */
    private function auditValues(Conversation $conversation): array
    {
        return [
            'inbox_id' => $conversation->inbox_id,
            'inbox_channel_id' => $conversation->inbox_channel_id,
            'contact_id' => $conversation->contact_id,
            'status' => $conversation->status,
            'priority' => $conversation->priority,
            'assigned_user_id' => $conversation->assigned_user_id,
            'assigned_role_id' => $conversation->assigned_role_id,
        ];
    }
}
