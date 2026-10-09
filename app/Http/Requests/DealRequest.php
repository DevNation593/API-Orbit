<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class DealRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['currency' => 'upper', 'status' => 'lower', 'forecast_category' => 'lower'] as $field => $case) {
            if (! $this->has($field) || ! is_string($this->input($field))) {
                continue;
            }
            $value = trim((string) $this->input($field));
            $normalized[$field] = $case === 'upper' ? strtoupper($value) : strtolower($value);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'pipeline_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', Rule::exists('pipelines', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'stage_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', Rule::exists('pipeline_stages', 'id')->where(fn ($query) => $query->whereIn('pipeline_id', fn ($query) => $query->select('id')->from('pipelines')->where('tenant_id', $tenantId)->where('active', true)))],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'sales_team_id' => ['nullable', 'integer', Rule::exists('sales_teams', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'territory_id' => ['nullable', 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:190'],
            'value' => [$this->isMethod('post') ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'currency' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'size:3', 'alpha'],
            'status' => ['sometimes', Rule::in(['open', 'won', 'lost'])],
            'forecast_category' => ['sometimes', Rule::in(['omitted', 'pipeline', 'best_case', 'commit', 'closed'])],
            'expected_close_date' => ['nullable', 'date'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
