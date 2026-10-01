<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class OrganizationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:190'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'territory_id' => ['nullable', 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'industry' => ['nullable', 'string', 'max:120'],
            'custom_fields' => ['sometimes', 'array'],
            'contact_ids' => ['sometimes', 'array', 'max:100'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
        ];
    }
}
