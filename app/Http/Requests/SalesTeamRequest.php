<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class SalesTeamRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales_structure.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('sales_teams', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'manager_id' => ['nullable', 'integer', $this->memberRule()],
            'name' => [$required, 'string', 'max:160', Rule::unique('sales_teams', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('team'))],
            'description' => ['nullable', 'string', 'max:10000'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:100'],
        ];
    }
}
