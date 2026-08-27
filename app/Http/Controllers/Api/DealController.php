<?php

namespace App\Http\Controllers\Api;

use App\Events\DealStageChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\DealRequest;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\CustomFieldService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;

class DealController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
    ) {}

    public function index(DealRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Deal::class);
        $query = Deal::query()->with(['pipeline:id,name', 'stage:id,pipeline_id,name', 'owner:id,name', 'contact:id,first_name,last_name', 'organization:id,name']);
        $query = $this->filters->apply($query, $request, [
            'name' => 'deals.name', 'status' => 'deals.status', 'currency' => 'deals.currency',
            'pipeline_id' => 'deals.pipeline_id', 'stage_id' => 'deals.stage_id', 'owner_id' => 'deals.owner_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(DealRequest $request): JsonResponse
    {
        $this->authorize('create', Deal::class);
        $data = $request->validated();
        $data['custom_fields'] = $this->customFields->validateAndNormalise('deals', $data['custom_fields'] ?? []);
        $this->assertRelations($data);
        $deal = Deal::create($data);
        $this->audit->record('create', $deal, newValues: $deal->getAttributes());

        return ApiResponse::success($deal->load(['pipeline:id,name', 'stage:id,pipeline_id,name']), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $deal = Deal::query()->with(['pipeline:id,name', 'stage:id,pipeline_id,name', 'owner:id,name', 'contact:id,first_name,last_name', 'organization:id,name'])->findOrFail($id);
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
        $this->assertRelations(array_merge($deal->only(['pipeline_id', 'stage_id', 'contact_id', 'organization_id']), $data));
        $oldStage = $deal->stage_id;
        $old = $deal->getAttributes();
        $deal->update($data);
        $this->audit->record('update', $deal, oldValues: $old, newValues: $deal->getAttributes());
        if ((int) $oldStage !== (int) $deal->stage_id) {
            DealStageChanged::dispatch($deal, (int) $oldStage, (int) $deal->stage_id);
        }

        return ApiResponse::success($deal->fresh()->load(['pipeline:id,name', 'stage:id,pipeline_id,name', 'owner:id,name']));
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
                abort(422, "The selected $key does not belong to the active tenant.");
            }
        }

        $pipeline = Pipeline::query()->findOrFail($data['pipeline_id']);
        $stage = PipelineStage::query()->findOrFail($data['stage_id']);
        abort_unless((int) $stage->pipeline_id === (int) $pipeline->id, 422, 'The stage does not belong to the pipeline.');
    }
}
