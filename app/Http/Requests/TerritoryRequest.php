<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class TerritoryRequest extends BaseApiRequest
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
            'parent_id' => ['nullable', 'integer', Rule::exists('territories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'manager_id' => ['nullable', 'integer', $this->memberRule()],
            'code' => [$required, 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('territories', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('territory'))],
            'name' => [$required, 'string', 'max:160'],
            'type' => [$required, Rule::in(['geographic', 'named', 'account', 'product', 'custom'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('code'))) {
            $data['code'] = strtoupper(trim($this->input('code')));
        }
        if (is_string($this->input('type'))) {
            $data['type'] = strtolower(trim($this->input('type')));
        }
        $this->merge($data);
    }
}
