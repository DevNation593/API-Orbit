<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ProductCategoryRequest extends BaseApiRequest
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
            'parent_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'name' => [$required, 'string', 'max:160', Rule::unique('product_categories', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('category'))],
            'description' => ['nullable', 'string', 'max:10000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
