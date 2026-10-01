<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ProductRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));

        return [
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'type' => [$required, Rule::in(['product', 'service', 'subscription', 'bundle'])],
            'sku' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('products', 'sku')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('product'))],
            'name' => [$required, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:50000'],
            'unit_of_measure' => ['sometimes', 'string', 'max:40'],
            'base_price' => [$required, 'decimal:0,6', 'min:0'],
            'cost' => ['nullable', 'decimal:0,6', 'min:0'],
            'taxable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'erp_product_id' => ['nullable', 'string', 'max:190'],
            'custom_fields' => ['nullable', 'array', 'max:200'],
            'metadata' => ['nullable', 'array', 'max:100'],
            'tax_ids' => ['sometimes', 'array', 'max:20'],
            'tax_ids.*' => ['integer', 'distinct', $exists('taxes')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['type', 'sku', 'unit_of_measure'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = $field === 'sku' ? trim($this->input($field)) : strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
