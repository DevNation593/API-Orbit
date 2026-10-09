<?php

namespace App\Http\Controllers\Api;

use App\Events\DealStageChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\DealRequest;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\SalesTeam;
use App\Models\Territory;
use App\Services\CustomFieldService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    private const RELATIONS = [
        'pipeline:id,name',
        'stage:id,pipeline_id,name',
        'owner:id,name',
        'contact:id,first_name,last_name',
        'organization:id,name',
        'team:id,branch_id,name',
        'branch:id,code,name',
        'territory:id,branch_id,code,name',
    ];

    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
    ) {}

    public function index(DealRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Deal::class);
        $query = Deal::query()->with(self::RELATIONS);
        $query = $this->filters->apply($query, $request, [
            'name' => 'deals.name', 'status' => 'deals.status', 'currency' => 'deals.currency',
            'pipeline_id' => 'deals.pipeline_id', 'stage_id' => 'deals.stage_id', 'owner_id' => 'deals.owner_id',
            'sales_team_id' => 'deals.sales_team_id', 'branch_id' => 'deals.branch_id', 'territory_id' => 'deals.territory_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(DealRequest $request): JsonResponse
    {
        $this->authorize('create', Deal::class);
        $data = $request->validated();
        $data['custom_fields'] = $this->customFields->validateAndNormalise('deals', $data['custom_fields'] ?? []);
        $data = $this->normalizeSalesScope($data);
        $this->assertRelations($data);
        $deal = Deal::create($data);
        $this->audit->record('create', $deal, newValues: $deal->getAttributes());

        return ApiResponse::success($deal->load(self::RELATIONS), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $deal = Deal::query()->with(self::RELATIONS)->findOrFail($id);
        $this->authorize('view', $deal);

        return ApiResponse::success($deal);
    }

    public function update(DealRequest $request, int $id): JsonResponse
    {
        $deal = Deal::findOrFail($id);
        $this->authorize('update', $deal);
        $data = $request->validated();
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = $this->customFields->validateAndNormalise('deals', array_merge($deal->custom_fields ?? [], $data['custom_fields'] ?? []));
        }
        $relations = $this->normalizeSalesScope(array_merge(
            $deal->only(['pipeline_id', 'stage_id', 'contact_id', 'organization_id', 'sales_team_id', 'branch_id', 'territory_id']),
            $data,
        ));
        $this->assertRelations($relations);
        foreach (['sales_team_id', 'branch_id', 'territory_id'] as $field) {
            $data[$field] = $relations[$field] ?? null;
        }
        $oldStage = $deal->stage_id;
        $old = $deal->getAttributes();
        $deal->update($data);
        $this->audit->record('update', $deal, oldValues: $old, newValues: $deal->getAttributes());
        if ((int) $oldStage !== (int) $deal->stage_id) {
            DealStageChanged::dispatch($deal, (int) $oldStage, (int) $deal->stage_id);
        }

        return ApiResponse::success($deal->fresh()->load(self::RELATIONS));
    }

    public function destroy(int $id): JsonResponse
    {
        $deal = Deal::findOrFail($id);
        $this->authorize('delete', $deal);
        $old = $deal->getAttributes();
        $deal->delete();
        $this->audit->record('delete', $deal, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    private function assertRelations(array $data): void
    {
        foreach ([
            'contact_id' => Contact::class,
            'organization_id' => Organization::class,
        ] as $key => $model) {
            if (isset($data[$key]) && ! $model::query()->whereKey($data[$key])->exists()) {
                throw ValidationException::withMessages([$key => "The selected $key does not belong to the active tenant."]);
            }
        }

        $pipeline = Pipeline::query()->findOrFail($data['pipeline_id']);
        $stage = PipelineStage::query()->findOrFail($data['stage_id']);
        if ((int) $stage->pipeline_id !== (int) $pipeline->id) {
            throw ValidationException::withMessages(['stage_id' => 'The stage does not belong to the pipeline.']);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeSalesScope(array $data): array
    {
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if (isset($data['sales_team_id'])) {
            $team = SalesTeam::query()->where('active', true)->findOrFail($data['sales_team_id']);
            $branchId = $this->resolveBranch($branchId, $team->branch_id, 'sales_team_id');
        }
        if (isset($data['territory_id'])) {
            $territory = Territory::query()->where('active', true)->findOrFail($data['territory_id']);
            $branchId = $this->resolveBranch($branchId, $territory->branch_id, 'territory_id');
        }
        if ($branchId !== null || array_key_exists('branch_id', $data)) {
            if ($branchId !== null) {
                Branch::query()->where('active', true)->findOrFail($branchId);
            }
            $data['branch_id'] = $branchId;
        }

        return $data;
    }

    private function resolveBranch(?int $current, mixed $related, string $field): ?int
    {
        if ($related === null) {
            return $current;
        }
        $related = (int) $related;
        if ($current !== null && $current !== $related) {
            throw ValidationException::withMessages([
                'branch_id' => "The selected branch is not compatible with $field.",
            ]);
        }

        return $related;
    }
}
