<?php

namespace App\Http\Controllers\Api;

use App\Events\ContactCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\CustomFieldService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\QueryFilters;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly DatabaseManager $database,
    ) {}

    public function index(ContactRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);
        $query = Contact::query()->with(['owner:id,name', 'organizations:id,name']);
        $query = $this->filters->apply($query, $request, [
            'first_name' => 'contacts.first_name', 'last_name' => 'contacts.last_name',
            'email' => 'contacts.email', 'phone' => 'contacts.phone',
            'status' => 'contacts.status', 'owner_id' => 'contacts.owner_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(ContactRequest $request): JsonResponse
    {
        $this->authorize('create', Contact::class);
        $data = $request->validated();
        $organizationIds = $data['organization_ids'] ?? [];
        unset($data['organization_ids']);
        $data['custom_fields'] = $this->customFields->validateAndNormalise('contacts', $data['custom_fields'] ?? []);

        $contact = $this->database->transaction(function () use ($data, $organizationIds): Contact {
            $contact = Contact::create($data);
            $contact->organizations()->sync($this->organizationPivot($this->tenantOrganizationIds($organizationIds)));
            $this->audit->record('create', $contact, newValues: $contact->getAttributes());
            ContactCreated::dispatch($contact);

            return $contact;
        });

        return ApiResponse::success($contact->load(['owner:id,name', 'organizations:id,name']), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $contact = Contact::query()->with(['owner:id,name', 'organizations:id,name'])->findOrFail($id);
        $this->authorize('view', $contact);

        return ApiResponse::success($contact);
    }

    public function update(ContactRequest $request, int $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('update', $contact);
        $data = $request->validated();
        $organizationIds = $data['organization_ids'] ?? null;
        unset($data['organization_ids']);

        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = $this->customFields->validateAndNormalise(
                'contacts',
                array_merge($contact->custom_fields ?? [], $data['custom_fields'] ?? []),
            );
        }

        $old = $contact->getAttributes();
        $this->database->transaction(function () use ($contact, $data, $organizationIds): void {
            $contact->update($data);
            if ($organizationIds !== null) {
                $contact->organizations()->sync($this->organizationPivot($this->tenantOrganizationIds($organizationIds)));
            }
        });
        $this->audit->record('update', $contact, oldValues: $old, newValues: $contact->getAttributes());

        return ApiResponse::success($contact->fresh()->load(['owner:id,name', 'organizations:id,name']));
    }

    public function destroy(int $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('delete', $contact);
        $old = $contact->getAttributes();
        $contact->delete();
        $this->audit->record('delete', $contact, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<int, int|string> $ids */
    private function tenantOrganizationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $safe = Organization::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($safe) !== count(array_unique($ids))) {
            abort(422, 'One or more organizations do not belong to the active tenant.');
        }

        return $safe;
    }

    /** @param array<int, int> $ids */
    private function organizationPivot(array $ids): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => $tenantId]])->all();
    }
}
