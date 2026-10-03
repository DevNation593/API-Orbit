<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadRoutingRuleRequest;
use App\Models\Lead;
use App\Models\LeadRoutingRule;
use App\Services\LeadRoutingService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadRoutingRuleController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LeadRoutingService $routing,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadRoutingRule::class);
        $query = LeadRoutingRule::withCount('executions')->with(['conditions', 'actions.user:id,name,email'])
            ->when($request->has('active'), fn ($builder) => $builder->where('active', $request->boolean('active')))
            ->orderBy('priority')->orderBy('id');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(LeadRoutingRuleRequest $request): JsonResponse
    {
        $this->authorize('create', LeadRoutingRule::class);
        $rule = $this->persist(new LeadRoutingRule, $request->validated());

        return ApiResponse::success($rule, [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $rule = LeadRoutingRule::with(['conditions', 'actions.user:id,name,email'])->withCount('executions')->findOrFail($id);
        $this->authorize('view', $rule);

        return ApiResponse::success($rule);
    }

    public function update(LeadRoutingRuleRequest $request, int $id): JsonResponse
    {
        $rule = LeadRoutingRule::findOrFail($id);
        $this->authorize('update', $rule);

        return ApiResponse::success($this->persist($rule, $request->validated()));
    }

    public function destroy(int $id): JsonResponse
    {
        $rule = LeadRoutingRule::findOrFail($id);
        $this->authorize('delete', $rule);
        $old = $rule->getAttributes();
        $rule->delete();
        $this->audit->record('delete', $rule, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    public function routeLead(Request $request, int $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('lead_routing.manage') && $request->user()->hasPermission('leads.update'), 403);
        $model = Lead::findOrFail($lead);
        $this->authorize('update', $model);
        $execution = $this->routing->route($model, 'manual-'.Str::uuid(), true);

        return ApiResponse::success($execution);
    }

    public function executions(Request $request, int $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('lead_routing.view'), 403);
        $model = Lead::findOrFail($lead);
        $this->authorize('view', $model);
        $query = $model->routingExecutions()->with(['rule:id,name,strategy', 'selectedUser:id,name,email'])->orderByDesc('executed_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    /** @param array<string, mixed> $data */
    private function persist(LeadRoutingRule $rule, array $data): LeadRoutingRule
    {
        return $this->database->transaction(function () use ($rule, $data): LeadRoutingRule {
            $conditions = $data['conditions'] ?? null;
            $actions = $data['actions'] ?? null;
            unset($data['conditions'], $data['actions']);
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
            if (is_array($actions)) {
                $rule->actions()->delete();
                foreach (array_values($actions) as $position => $action) {
                    $action['position'] = $action['position'] ?? $position;
                    $rule->actions()->create($action);
                }
            }
            $this->audit->record($exists ? 'update' : 'create', $rule, oldValues: $old, newValues: $rule->getAttributes());

            return $rule->fresh()->load(['conditions', 'actions.user:id,name,email']);
        });
    }
}
