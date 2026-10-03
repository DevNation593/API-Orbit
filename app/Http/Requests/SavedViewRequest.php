<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class SavedViewRequest extends BaseApiRequest
{
    public const ENTITY_TYPES = [
        'contacts', 'companies', 'organizations', 'leads', 'opportunities', 'deals',
        'tasks', 'activities', 'documents', 'files', 'custom_objects',
    ];

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'entity_type' => [$required, 'string', Rule::in(self::ENTITY_TYPES)],
            'name' => [$required, 'string', 'max:120'],
            'visibility' => ['sometimes', 'string', Rule::in(['private', 'team', 'tenant'])],
            'shared_with_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId()),
            )],
            'sort_field' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z][A-Za-z0-9_.:-]*$/'],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'columns' => ['sometimes', 'array', 'max:100'],
            'columns.*' => ['string', 'max:120', 'distinct', 'regex:/^[A-Za-z][A-Za-z0-9_.:-]*$/'],
            'is_default' => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'array', 'max:50'],
            'filters.*.field' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z][A-Za-z0-9_.:-]*$/'],
            'filters.*.operator' => ['required', Rule::in(config('tenancy.allowed_filter_operators', []))],
            'filters.*.value' => ['present', 'nullable'],
        ];
    }
}
