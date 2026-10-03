<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ForecastRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('forecast.view') === true
            || $this->user()?->hasPermission('forecast.manage') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'starts_at' => ['nullable', 'date_format:Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'pipeline_id' => ['nullable', 'integer', Rule::exists('pipelines', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'team_id' => ['nullable', 'integer', Rule::exists('sales_teams', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'territory_id' => ['nullable', 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
