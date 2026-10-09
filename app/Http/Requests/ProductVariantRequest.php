<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ProductVariantRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'sku' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('product_variants', 'sku')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('variant'))],
            'name' => [$required, 'string', 'max:190'],
            'attributes' => ['nullable', 'array', 'max:100'],
            'price_adjustment' => ['sometimes', 'decimal:0,6'],
            'cost' => ['nullable', 'decimal:0,6', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'erp_product_id' => ['nullable', 'string', 'max:190'],
        ];
    }
}
