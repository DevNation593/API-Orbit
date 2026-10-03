<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaybookRequest;
use App\Models\Playbook;
use App\Services\PlaybookService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybookController extends Controller
{
    public function __construct(private readonly PlaybookService $playbooks) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Playbook::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'entity_type' => ['nullable', 'in:contact,organization,lead,deal'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Playbook::query()->with('creator:id,name,email')->withCount(['sections', 'executions']);
        if (isset($data['entity_type'])) {
            $query->where('entity_type', $data['entity_type']);
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if (filled($data['q'] ?? null)) {
            $query->where('name', 'like', '%'.addcslashes((string) $data['q'], '%_\\').'%');
        }

        return ApiResponse::paginated($query->orderBy('name')->orderByDesc('version')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(PlaybookRequest $request): JsonResponse
    {
        $this->authorize('create', Playbook::class);

        return ApiResponse::success($this->playbooks->save(new Playbook, $request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $playbook): JsonResponse
    {
        $model = Playbook::query()->with(['creator:id,name,email', 'sections.questions'])->withCount('executions')->findOrFail($playbook);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(PlaybookRequest $request, int $playbook): JsonResponse
    {
        $model = Playbook::query()->findOrFail($playbook);
        $this->authorize('update', $model);

        return ApiResponse::success($this->playbooks->save($model, $request->validated(), (int) $request->user()->id));
    }

    public function destroy(int $playbook): JsonResponse
    {
        $model = Playbook::query()->findOrFail($playbook);
        $this->authorize('delete', $model);
        $this->playbooks->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }

    public function version(Request $request, int $playbook): JsonResponse
    {
        $model = Playbook::query()->findOrFail($playbook);
        $this->authorize('update', $model);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:20000'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:100'],
        ]);

        return ApiResponse::success($this->playbooks->createVersion($model, $data, (int) $request->user()->id), [], 201);
    }
}
