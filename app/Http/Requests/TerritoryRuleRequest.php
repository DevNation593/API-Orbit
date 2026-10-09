<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class TerritoryRuleRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('territories.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'territory_id' => [$required, 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'name' => [$required, 'string', 'max:160', Rule::unique('territory_rules', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($this->route('rule'))],
            'entity_type' => [$required, Rule::in(['contact', 'organization', 'lead', 'deal'])],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'conditions' => [$required, 'array', 'between:1,100'],
            'conditions.*.field' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,190}$/'],
            'conditions.*.operator' => ['required', Rule::in(['eq', 'neq', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_null', 'not_null', 'is_empty', 'is_not_empty', 'before', 'after'])],
            'conditions.*.value' => ['nullable'],
            'match_type' => ['sometimes', Rule::in(['all', 'any'])],
            'stop_processing' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
