<?php

namespace App\Http\Requests;

use App\Models\ProductDependency;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductDependencyRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pricing.manage') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();
        $product = Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'));

        return [
            'product_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', $product],
            'related_product_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', $product],
            'relation' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(['requires', 'excludes', 'recommends'])],
            'minimum_quantity' => ['sometimes', 'decimal:0,6', 'gt:0'],
            'conditions' => ['nullable', 'array', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $dependency = $this->isMethod('post') ? null : ProductDependency::query()->find($this->route('dependency'));
            $productId = (int) $this->effectiveInput('product_id', $dependency?->product_id);
            $relatedProductId = (int) $this->effectiveInput('related_product_id', $dependency?->related_product_id);
            if ($productId > 0 && $productId === $relatedProductId) {
                $validator->errors()->add('related_product_id', 'A product cannot depend on itself.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('relation'))) {
            $this->merge(['relation' => strtolower(trim($this->input('relation')))]);
        }
    }
}
