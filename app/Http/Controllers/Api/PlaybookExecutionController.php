<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaybookAnswerRequest;
use App\Http\Requests\PlaybookExecutionRequest;
use App\Models\PlaybookExecution;
use App\Services\PlaybookService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybookExecutionController extends Controller
{
    public function __construct(private readonly PlaybookService $playbooks) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlaybookExecution::class);
        $data = $request->validate([
            'playbook_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'in:contact,organization,lead,deal'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'assigned_to' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:in_progress,completed,cancelled'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = PlaybookExecution::query()->with([
            'playbook:id,name,entity_type,version', 'executable', 'assignee:id,name,email', 'starter:id,name,email',
        ])->withCount(['answers', 'actionLogs']);
        foreach (['playbook_id', 'assigned_to', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['entity_type'])) {
            $query->where('executable_type', $data['entity_type']);
        }
        if (isset($data['entity_id'])) {
            $query->where('executable_id', $data['entity_id']);
        }

        return ApiResponse::paginated($query->latest('started_at')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(PlaybookExecutionRequest $request): JsonResponse
    {
        $this->authorize('create', PlaybookExecution::class);

        return ApiResponse::success($this->playbooks->start($request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $execution): JsonResponse
    {
        $model = PlaybookExecution::query()->with([
            'playbook.creator:id,name,email', 'playbook.sections.questions', 'executable',
            'assignee:id,name,email', 'starter:id,name,email', 'answers.question',
            'answers.answerer:id,name,email', 'actionLogs',
        ])->findOrFail($execution);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function answer(PlaybookAnswerRequest $request, int $execution): JsonResponse
    {
        $model = PlaybookExecution::query()->findOrFail($execution);
        $this->authorize('update', $model);
        $data = $request->validated();

        return ApiResponse::success($this->playbooks->answer($model, (int) $data['question_id'], $data['value'], (int) $request->user()->id));
    }

    public function complete(int $execution): JsonResponse
    {
        $model = PlaybookExecution::query()->findOrFail($execution);
        $this->authorize('update', $model);

        return ApiResponse::success($this->playbooks->complete($model));
    }

    public function cancel(int $execution): JsonResponse
    {
        $model = PlaybookExecution::query()->findOrFail($execution);
        $this->authorize('update', $model);

        return ApiResponse::success($this->playbooks->cancel($model));
    }
}
