<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationRequest;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\CustomFieldService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly DatabaseManager $database,
    ) {}

    public function index(OrganizationRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);
        $query = Organization::query()->with(['owner:id,name']);
        $query = $this->filters->apply($query, $request, [
            'name' => 'organizations.name', 'email' => 'organizations.email',
            'phone' => 'organizations.phone', 'owner_id' => 'organizations.owner_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(OrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);
        $data = $request->validated();
        $contactIds = $data['contact_ids'] ?? [];
        unset($data['contact_ids']);
        $data['custom_fields'] = $this->customFields->validateAndNormalise('organizations', $data['custom_fields'] ?? []);

        $organization = $this->database->transaction(function () use ($data, $contactIds): Organization {
            $organization = Organization::create($data);
            $organization->contacts()->sync($this->contactPivot($this->tenantContactIds($contactIds)));
            $this->audit->record('create', $organization, newValues: $organization->getAttributes());

            return $organization;
        });

        return ApiResponse::success($organization->load(['owner:id,name', 'contacts:id,first_name,last_name']), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $organization = Organization::query()->with(['owner:id,name', 'contacts:id,first_name,last_name'])->findOrFail($id);
        $this->authorize('view', $organization);

        return ApiResponse::success($organization);
    }

    public function update(OrganizationRequest $request, int $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);
        $this->authorize('update', $organization);
        $data = $request->validated();
        $contactIds = $data['contact_ids'] ?? null;
        unset($data['contact_ids']);

        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = $this->customFields->validateAndNormalise(
                'organizations',
                array_merge($organization->custom_fields ?? [], $data['custom_fields'] ?? []),
            );
        }

        $old = $organization->getAttributes();
        $this->database->transaction(function () use ($organization, $data, $contactIds): void {
            $organization->update($data);
            if ($contactIds !== null) {
                $organization->contacts()->sync($this->contactPivot($this->tenantContactIds($contactIds)));
            }
        });
        $this->audit->record('update', $organization, oldValues: $old, newValues: $organization->getAttributes());

        return ApiResponse::success($organization->fresh()->load(['owner:id,name', 'contacts:id,first_name,last_name']));
    }

    public function destroy(int $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);
        $this->authorize('delete', $organization);
        $old = $organization->getAttributes();
        $organization->delete();
        $this->audit->record('delete', $organization, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<int, int|string> $ids */
    private function tenantContactIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $safe = Contact::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($safe) !== count(array_unique($ids))) {
            abort(422, 'One or more contacts do not belong to the active tenant.');
        }

        return $safe;
    }

    /** @param array<int, int> $ids */
    private function contactPivot(array $ids): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => $tenantId]])->all();
    }
}
