<?php

namespace App\Http\Requests;

use App\Models\Tax;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaxRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pricing.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'code' => [$required, 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('taxes', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($this->route('tax'))],
            'name' => [$required, 'string', 'max:120'],
            'calculation' => [$required, Rule::in(['percentage', 'fixed'])],
            'rate' => [$required, 'decimal:0,6', 'min:0'],
            'inclusive' => ['sometimes', 'boolean'],
            'compound' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $tax = $this->isMethod('post') ? null : Tax::query()->find($this->route('tax'));
            $calculation = $this->effectiveInput('calculation', $tax?->calculation);
            $currencyId = $this->effectiveInput('currency_id', $tax?->currency_id);
            if ($calculation === 'fixed' && blank($currencyId)) {
                $validator->errors()->add('currency_id', 'A fixed tax requires a currency.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('code'))) {
            $data['code'] = strtoupper(trim($this->input('code')));
        }
        if (is_string($this->input('calculation'))) {
            $data['calculation'] = strtolower(trim($this->input('calculation')));
        }
        $this->merge($data);
    }
}
