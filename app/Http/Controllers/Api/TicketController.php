<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TicketCommentRequest;
use App\Http\Requests\TicketRequest;
use App\Http\Requests\TicketStatusRequest;
use App\Http\Resources\SlaExecutionResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketCommentService;
use App\Services\TicketService;
use App\Support\ApiResponse;
use App\Support\SupportCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketCommentService $comments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);
        if (in_array($request->query('unassigned'), ['true', 'false'], true)) {
            $request->merge(['unassigned' => $request->query('unassigned') === 'true']);
        }
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(SupportCatalog::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportCatalog::PRIORITIES)],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'queue_id' => ['sometimes', 'integer', 'min:1'],
            'assigned_agent_id' => ['sometimes', 'integer', 'min:1'],
            'unassigned' => ['sometimes', 'boolean'],
            'created_from' => ['sometimes', 'date'], 'created_to' => ['sometimes', 'date'],
            'sort' => ['sometimes', Rule::in(['created_at', 'updated_at', 'priority'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (($data['unassigned'] ?? false) && isset($data['assigned_agent_id'])) {
            throw ValidationException::withMessages(['unassigned' => 'Do not combine unassigned with an assigned agent.']);
        }
        $from = isset($data['created_from']) ? CarbonImmutable::parse($data['created_from'])->utc() : null;
        $to = isset($data['created_to']) ? CarbonImmutable::parse($data['created_to'])->utc() : null;
        if ($from !== null && $to !== null && $from->gt($to)) {
            throw ValidationException::withMessages(['created_to' => 'The end must not precede the start.']);
        }
        $query = Ticket::query()->with(['sla', 'queue', 'assignee.user']);
        foreach (['status', 'priority', 'category_id', 'queue_id', 'assigned_agent_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q'])) {
            $query->where('subject', 'like', '%'.$data['q'].'%');
        }
        if ($data['unassigned'] ?? false) {
            $query->whereNull('assigned_agent_id');
        }
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
        $sort = $data['sort'] ?? 'created_at';
        $direction = $data['direction'] ?? ($sort === 'priority' ? 'asc' : 'desc');
        if ($sort === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END ".$direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        $page = $query->orderBy('id', $direction)->paginate($data['per_page'] ?? 25)->withQueryString();
        $page->through(fn (Ticket $ticket): array => $this->resource($ticket, $request));

        return ApiResponse::paginated($page);
    }

    public function store(TicketRequest $request): JsonResponse
    {
        Gate::authorize('create', Ticket::class);
        $data = $request->validated();
        if (array_key_exists('assigned_agent_id', $data)) {
            abort_unless($request->user()->hasPermission('tickets.assign'), 403);
        }
        $result = $this->tickets->create($data, $request->user());

        return ApiResponse::success($this->resource($result['ticket'], $request), [], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('view', $model);

        return ApiResponse::success($this->resource($model, $request));
    }

    public function update(TicketRequest $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->resource($this->tickets->update($model, $request->validated()), $request));
    }

    public function assign(Request $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('assign', $model);
        $data = $request->validate([
            'queue_id' => ['required', 'integer', 'min:1'],
            'assigned_agent_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);
        $assigned = $this->tickets->assign($model, (int) $data['queue_id'], isset($data['assigned_agent_id']) ? (int) $data['assigned_agent_id'] : null);

        return ApiResponse::success($this->resource($assigned, $request));
    }

    public function changeStatus(TicketStatusRequest $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('changeStatus', $model);
        $data = $request->validated();

        return ApiResponse::success($this->resource($this->tickets->changeStatus($model, $data['status'], $data), $request));
    }

    public function comments(Request $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('view', $model);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1']]);

        return ApiResponse::paginated($model->comments()->orderBy('id')->paginate($data['per_page'] ?? 25)->withQueryString());
    }

    public function storeComment(TicketCommentRequest $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('view', $model);
        $result = $this->comments->create($model, $request->validated(), $request->user());

        return ApiResponse::success($result['comment'], [], $result['replayed'] ? 200 : 201);
    }

    public function sla(Request $request, int $ticket): JsonResponse
    {
        $model = Ticket::findOrFail($ticket);
        Gate::authorize('view', $model);

        return ApiResponse::success($model->sla === null ? null : (new SlaExecutionResource($model->sla))->resolve($request));
    }

    private function resource(Ticket $ticket, Request $request): array
    {
        return (new TicketResource($ticket))->resolve($request);
    }
}
