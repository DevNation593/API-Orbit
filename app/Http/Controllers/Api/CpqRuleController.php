<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CpqRuleRequest;
use App\Models\ApprovalRule;
use App\Models\BundleRule;
use App\Models\DiscountRule;
use App\Models\PricingRule;
use App\Services\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CpqRuleController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request, string $ruleType): JsonResponse
    {
        $class = $this->class($ruleType);
        $this->authorize('viewAny', $class);
        $query = $class::query();
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ApiResponse::success($query->orderBy('priority')->orderBy('name')->get());
    }

    public function store(CpqRuleRequest $request, string $ruleType): JsonResponse
    {
        $class = $this->class($ruleType);
        $this->authorize('create', $class);

        return ApiResponse::success($this->catalog->saveSimple(new $class, $request->validated(), 'cpq_'.$ruleType.'_rule'), [], 201);
    }

    public function show(string $ruleType, int $rule): JsonResponse
    {
        $model = $this->find($ruleType, $rule);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(CpqRuleRequest $request, string $ruleType, int $rule): JsonResponse
    {
        $model = $this->find($ruleType, $rule);
        $this->authorize('update', $model);

        return ApiResponse::success($this->catalog->saveSimple($model, $request->validated(), 'cpq_'.$ruleType.'_rule'));
    }

    public function destroy(string $ruleType, int $rule): JsonResponse
    {
        $model = $this->find($ruleType, $rule);
        $this->authorize('delete', $model);
        $this->catalog->delete($model, 'cpq_'.$ruleType.'_rule_deleted');

        return ApiResponse::success(['deleted' => true]);
    }

    /** @return class-string<Model> */
    private function class(string $type): string
    {
        return match ($type) {
            'pricing' => PricingRule::class, 'discount' => DiscountRule::class,
            'bundle' => BundleRule::class, 'approval' => ApprovalRule::class,
            default => abort(404),
        };
    }

    private function find(string $type, int $id): Model
    {
        $class = $this->class($type);

        return $class::query()->findOrFail($id);
    }
}
