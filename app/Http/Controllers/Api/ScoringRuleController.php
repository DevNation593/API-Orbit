<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScoringRuleRequest;
use App\Models\ScoringModel;
use App\Models\ScoringRule;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

class ScoringRuleController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    public function store(ScoringRuleRequest $request, int $model): JsonResponse
    {
        $scoringModel = ScoringModel::findOrFail($model);
        $this->authorize('update', $scoringModel);

        return ApiResponse::success($this->persist(new ScoringRule(['scoring_model_id' => $model]), $request->validated()), [], 201);
    }

    public function update(ScoringRuleRequest $request, int $rule): JsonResponse
    {
        $model = ScoringRule::with('model')->findOrFail($rule);
        $this->authorize('update', $model->model);

        return ApiResponse::success($this->persist($model, $request->validated()));
    }

    public function destroy(int $rule): JsonResponse
    {
        $model = ScoringRule::with('model')->findOrFail($rule);
        $this->authorize('update', $model->model);
        $old = $model->getAttributes();
        $model->delete();
        $this->audit->record('delete', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<string, mixed> $data */
    private function persist(ScoringRule $rule, array $data): ScoringRule
    {
        return $this->database->transaction(function () use ($rule, $data): ScoringRule {
            $conditions = $data['conditions'] ?? null;
            unset($data['conditions']);
            if (($data['type'] ?? $rule->type) === 'negative' && isset($data['points'])) {
                $data['points'] = -abs((int) $data['points']);
            } elseif (isset($data['points'])) {
                $data['points'] = abs((int) $data['points']);
            }
            $exists = $rule->exists;
            $old = $exists ? $rule->getAttributes() : null;
            $rule->fill($data)->save();
            if (is_array($conditions)) {
                $rule->conditions()->delete();
                foreach (array_values($conditions) as $position => $condition) {
                    $condition['position'] = $condition['position'] ?? $position;
                    $rule->conditions()->create($condition);
                }
            }
            $this->audit->record($exists ? 'update' : 'create', $rule, oldValues: $old, newValues: $rule->getAttributes());

            return $rule->fresh()->load('conditions');
        });
    }
}
