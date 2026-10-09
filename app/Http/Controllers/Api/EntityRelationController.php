<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityRelationRequest;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\EntityDefinition;
use App\Models\EntityRecord;
use App\Models\EntityRelation;
use App\Models\Lead;
use App\Models\Organization;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\Money;
use App\Support\QueryFilters;
use App\Support\TenantRelationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EntityRelationController extends Controller
{
    public function __construct(
        private readonly QueryFilters $filters,
        private readonly AuditService $audit,
        private readonly TenantRelationResolver $relations,
    ) {}

    public function index(EntityRelationRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('relations.view'), 403, 'You do not have permission to view relations.');
        $query = EntityRelation::query();
        $query = $this->filters->apply($query, $request, [
            'relation_type' => 'entity_relations.relation_type',
            'from_type' => 'entity_relations.from_type', 'from_id' => 'entity_relations.from_id',
            'to_type' => 'entity_relations.to_type', 'to_id' => 'entity_relations.to_id',
        ]);

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function options(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('relations.view'), 403, 'You do not have permission to view relations.');

        $groups = collect([
            $this->standardGroup('contact', 'Contactos', Contact::query()->latest('id')->limit(100)->get(),
                fn (Contact $contact): array => [
                    'label' => trim($contact->first_name.' '.$contact->last_name),
                    'subtitle' => $contact->email,
                ]),
            $this->standardGroup('organization', 'Organizaciones', Organization::query()->latest('id')->limit(100)->get(),
                fn (Organization $organization): array => [
                    'label' => $organization->name,
                    'subtitle' => $organization->email,
                ]),
            $this->standardGroup('lead', 'Leads', Lead::query()->latest('id')->limit(100)->get(),
                fn (Lead $lead): array => [
                    'label' => trim($lead->first_name.' '.$lead->last_name),
                    'subtitle' => $lead->email,
                ]),
            $this->standardGroup('deal', 'Oportunidades', Deal::query()->latest('id')->limit(100)->get(),
                fn (Deal $deal): array => [
                    'label' => $deal->name,
                    'subtitle' => trim($deal->currency.' '.Money::of((string) $deal->value, 2)),
                ]),
        ]);

        EntityDefinition::query()
            ->where('active', true)
            ->orderBy('label')
            ->get()
            ->each(function (EntityDefinition $definition) use ($groups): void {
                $records = $definition->records()
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (EntityRecord $record): array => [
                        'id' => (string) $record->getKey(),
                        'label' => $this->dynamicRecordLabel($record),
                        'subtitle' => null,
                    ])
                    ->values();

                $groups->push([
                    'key' => 'entity:'.$definition->getKey(),
                    'type' => 'entity_record',
                    'label' => $definition->label,
                    'records' => $records,
                ]);
            });

        return ApiResponse::success($groups->values());
    }

    public function store(EntityRelationRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('relations.manage'), 403, 'You do not have permission to manage relations.');
        $data = $request->validated();
        $data['from_type'] = $this->relations->canonicalEntityType($data['from_type'], $data['from_id']);
        $data['to_type'] = $this->relations->canonicalEntityType($data['to_type'], $data['to_id']);
        $data['from_id'] = (string) $data['from_id'];
        $data['to_id'] = (string) $data['to_id'];

        if ($data['from_type'] === $data['to_type'] && $data['from_id'] === $data['to_id']) {
            throw ValidationException::withMessages([
                'to_id' => 'A record cannot be related to itself.',
            ]);
        }

        if (EntityRelation::query()
            ->where('relation_type', $data['relation_type'])
            ->where('from_type', $data['from_type'])
            ->where('from_id', $data['from_id'])
            ->where('to_type', $data['to_type'])
            ->where('to_id', $data['to_id'])
            ->exists()) {
            throw ValidationException::withMessages([
                'relation' => 'This relation already exists.',
            ]);
        }

        $relation = EntityRelation::create($data);
        $this->audit->record('create', $relation, newValues: $relation->getAttributes());

        return ApiResponse::success($relation, [], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('relations.manage'), 403, 'You do not have permission to manage relations.');
        $relation = EntityRelation::findOrFail($id);
        $old = $relation->getAttributes();
        $relation->delete();
        $this->audit->record('delete', $relation, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @param  callable(mixed): array{label: string, subtitle: ?string}  $present
     * @return array{key: string, type: string, label: string, records: Collection<int, array{id: string, label: string, subtitle: ?string}>}
     */
    private function standardGroup(string $type, string $label, Collection $records, callable $present): array
    {
        return [
            'key' => $type,
            'type' => $type,
            'label' => $label,
            'records' => $records->map(function ($record) use ($present): array {
                $presentation = $present($record);

                return [
                    'id' => (string) $record->getKey(),
                    'label' => $presentation['label'] ?: '#'.$record->getKey(),
                    'subtitle' => $presentation['subtitle'] ?: null,
                ];
            })->values(),
        ];
    }

    private function dynamicRecordLabel(EntityRecord $record): string
    {
        $data = $record->data ?? [];
        foreach (['name', 'title', 'label', 'email'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return (string) $data[$key];
            }
        }

        $fullName = trim(implode(' ', array_filter([
            isset($data['first_name']) && is_scalar($data['first_name']) ? (string) $data['first_name'] : null,
            isset($data['last_name']) && is_scalar($data['last_name']) ? (string) $data['last_name'] : null,
        ])));

        return $fullName !== '' ? $fullName : '#'.$record->getKey();
    }
}
