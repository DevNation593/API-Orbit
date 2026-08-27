<?php

namespace App\Http\Controllers\Api;

use App\Events\LeadCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertLeadRequest;
use App\Http\Requests\LeadRequest;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\CustomFieldService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly DatabaseManager $database,
    ) {}

    public function index(LeadRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);
        $query = Lead::query()->with(['owner:id,name', 'contact:id,first_name,last_name', 'organization:id,name']);
        $query = $this->filters->apply($query, $request, [
            'first_name' => 'leads.first_name', 'last_name' => 'leads.last_name',
            'email' => 'leads.email', 'source' => 'leads.source',
            'status' => 'leads.status', 'owner_id' => 'leads.owner_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(LeadRequest $request): JsonResponse
    {
        $this->authorize('create', Lead::class);
        $data = $request->validated();
        $data['custom_fields'] = $this->customFields->validateAndNormalise('leads', $data['custom_fields'] ?? []);
        $this->assertTenantRelations($data);
        $lead = $this->database->transaction(function () use ($data): Lead {
            $lead = Lead::create($data);
            $this->audit->record('create', $lead, newValues: $lead->getAttributes());
            LeadCreated::dispatch($lead);

            return $lead;
        });

        return ApiResponse::success($lead->load(['owner:id,name', 'contact:id,first_name,last_name', 'organization:id,name']), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $lead = Lead::query()->with(['owner:id,name', 'contact:id,first_name,last_name', 'organization:id,name'])->findOrFail($id);
        $this->authorize('view', $lead);

        return ApiResponse::success($lead);
    }

    public function update(LeadRequest $request, int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $this->authorize('update', $lead);
        $data = $request->validated();
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = $this->customFields->validateAndNormalise(
                'leads',
                array_merge($lead->custom_fields ?? [], $data['custom_fields'] ?? []),
            );
        }
        $this->assertTenantRelations($data);
        $old = $lead->getAttributes();
        $lead->update($data);
        $this->audit->record('update', $lead, oldValues: $old, newValues: $lead->getAttributes());

        return ApiResponse::success($lead->fresh()->load(['owner:id,name', 'contact:id,first_name,last_name', 'organization:id,name']));
    }

    public function destroy(int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $this->authorize('delete', $lead);
        $old = $lead->getAttributes();
        $lead->delete();
        $this->audit->record('delete', $lead, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    public function convert(ConvertLeadRequest $request, int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $this->authorize('convert', $lead);
        if ($lead->converted_at !== null) {
            return ApiResponse::error('This lead has already been converted.', [], 409);
        }

        $data = $request->validated();
        $this->assertTenantRelations($data);
        $result = $this->database->transaction(function () use ($lead, $data): array {
            $contact = ! empty($data['contact_id'])
                ? Contact::findOrFail($data['contact_id'])
                : null;
            $organization = ! empty($data['organization_id'])
                ? Organization::findOrFail($data['organization_id'])
                : null;

            if ($contact === null && ($data['create_contact'] ?? true)) {
                $contact = Contact::create([
                    'first_name' => $lead->first_name ?: 'Unknown',
                    'last_name' => $lead->last_name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'owner_id' => $lead->owner_id,
                    'status' => 'active',
                    'custom_fields' => $lead->custom_fields ?? [],
                ]);
            }

            if ($organization === null && ($data['create_organization'] ?? false)) {
                $organization = Organization::create([
                    'name' => trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: 'New organization',
                    'owner_id' => $lead->owner_id,
                ]);
            }

            $deal = null;
            if (! empty($data['deal'])) {
                $dealData = $data['deal'];
                $stage = PipelineStage::query()->whereKey($dealData['stage_id'])->firstOrFail();
                $pipeline = Pipeline::query()->whereKey($dealData['pipeline_id'])->firstOrFail();
                abort_unless((int) $stage->pipeline_id === (int) $pipeline->id, 422, 'The stage does not belong to the pipeline.');
                $deal = Deal::create([
                    ...$dealData,
                    'owner_id' => $lead->owner_id,
                    'contact_id' => $contact?->id,
                    'organization_id' => $organization?->id,
                    'status' => 'open',
                ]);
            }

            $lead->update([
                'contact_id' => $contact?->id,
                'organization_id' => $organization?->id,
                'status' => 'converted',
                'converted_at' => now(),
            ]);
            $this->audit->record('convert', $lead, newValues: [
                'contact_id' => $contact?->id,
                'organization_id' => $organization?->id,
                'deal_id' => $deal?->id,
            ]);

            return compact('lead', 'contact', 'organization', 'deal');
        });

        return ApiResponse::success($result);
    }

    private function assertTenantRelations(array $data): void
    {
        foreach ([
            'contact_id' => Contact::class,
            'organization_id' => Organization::class,
        ] as $key => $model) {
            if (isset($data[$key]) && ! $model::query()->whereKey($data[$key])->exists()) {
                abort(422, "The selected $key does not belong to the active tenant.");
            }
        }
    }
}
