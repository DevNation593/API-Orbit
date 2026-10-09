<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class TerritoryAssignmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('territories.manage') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'entity_type' => ['required', Rule::in(['contact', 'organization', 'lead', 'deal'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'territory_id' => ['nullable', 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'evaluate_rules' => ['sometimes', 'boolean'],
        ];
    }
}
