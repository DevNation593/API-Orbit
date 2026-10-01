<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MessageRequest;
use App\Models\Conversation;
use App\Services\ConversationMessageService;
use App\Services\IdempotencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMessageController extends Controller
{
    public function __construct(
        private readonly ConversationMessageService $messages,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function index(Request $request, int $conversation): JsonResponse
    {
        $model = Conversation::findOrFail($conversation);
        $this->authorize('view', $model);
        $request->validate([
            'direction' => ['sometimes', 'in:inbound,outbound'],
            'type' => ['sometimes', 'string', 'max:30'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $model->messages()->with([
            'senderUser:id,name', 'senderContact:id,first_name,last_name', 'attachments.file',
        ]);
        foreach (['direction', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('before_id')) {
            $query->where('id', '<', $request->integer('before_id'));
        }

        return ApiResponse::paginated($query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request->input('per_page', 50), 50))->withQueryString());
    }

    public function store(MessageRequest $request, int $conversation): JsonResponse
    {
        $model = Conversation::findOrFail($conversation);
        $this->authorize('reply', $model);
        if ($response = $this->idempotency->replay($request)) {
            return $response;
        }

        $result = $this->messages->send($model, $request->user(), $request->validated());
        $response = ApiResponse::success(
            $result['message'],
            ['replayed' => $result['replayed']],
            $result['replayed'] ? 200 : 202,
        );
        $this->idempotency->remember($request, $response);

        return $response;
    }
}
