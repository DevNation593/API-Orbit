<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScoringModelRequest;
use App\Models\ScoringModel;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoringModelController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ScoringModel::class);
        $query = ScoringModel::query()->withCount(['rules', 'scores'])
            ->when($request->has('active'), fn ($builder) => $builder->where('active', $request->boolean('active')))
            ->orderByDesc('is_default')->orderBy('name');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(ScoringModelRequest $request): JsonResponse
    {
        $this->authorize('create', ScoringModel::class);

        return ApiResponse::success($this->persist(new ScoringModel, $request->validated()), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $model = ScoringModel::with(['rules.conditions'])->withCount('scores')->findOrFail($id);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(ScoringModelRequest $request, int $id): JsonResponse
    {
        $model = ScoringModel::findOrFail($id);
        $this->authorize('update', $model);

        return ApiResponse::success($this->persist($model, $request->validated()));
    }

    public function destroy(int $id): JsonResponse
    {
        $model = ScoringModel::findOrFail($id);
        $this->authorize('delete', $model);
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<string, mixed> $data */
    private function persist(ScoringModel $model, array $data): ScoringModel
    {
        return $this->database->transaction(function () use ($model, $data): ScoringModel {
            $exists = $model->exists;
            $old = $exists ? $model->getAttributes() : null;
            if (($data['is_default'] ?? false) === true) {
                ScoringModel::query()->where('id', '!=', $model->id ?? 0)->update(['is_default' => false]);
            }
            $model->fill($data)->save();
            $this->audit->record($exists ? 'update' : 'create', $model, oldValues: $old, newValues: $model->getAttributes());

            return $model->fresh()->loadCount(['rules', 'scores']);
        });
    }
}
