<?php

namespace App\Http\Requests;

use App\Services\LeadCaptureService;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class LeadRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:100'],
            'capture_origin' => ['sometimes', Rule::in(LeadCaptureService::ORIGINS)],
            'duplicate_strategy' => ['sometimes', Rule::in(['create', 'update', 'reject'])],
            'medium' => ['nullable', 'string', 'max:190'],
            'campaign' => ['nullable', 'string', 'max:190'],
            'content' => ['nullable', 'string', 'max:190'],
            'term' => ['nullable', 'string', 'max:190'],
            'utm_source' => ['nullable', 'string', 'max:190'],
            'utm_medium' => ['nullable', 'string', 'max:190'],
            'utm_campaign' => ['nullable', 'string', 'max:190'],
            'utm_content' => ['nullable', 'string', 'max:190'],
            'utm_term' => ['nullable', 'string', 'max:190'],
            'landing_page' => ['nullable', 'url:http,https', 'max:2000'],
            'referrer' => ['nullable', 'url:http,https', 'max:2000'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'territory_id' => ['nullable', 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'status' => ['sometimes', 'string', 'max:40'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['capture_origin', 'duplicate_strategy'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = mb_strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
