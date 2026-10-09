<?php

namespace App\Http\Requests;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BundleRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();
        $product = Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true));

        return [
            'product_id' => [$required, 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('type', 'bundle')->whereNull('deleted_at'))],
            'name' => [$required, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
            'pricing_method' => [$required, Rule::in(['fixed', 'sum_components'])],
            'fixed_price' => ['nullable', 'decimal:0,6', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:50'],
            'items' => [$required, 'array', 'between:1,200'],
            'items.*.product_id' => ['required', 'integer', $product],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'items.*.quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'items.*.price_override' => ['nullable', 'decimal:0,6', 'min:0'],
            'items.*.required' => ['sometimes', 'boolean'],
            'items.*.position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $bundle = $this->isMethod('post') ? null : Bundle::query()->find($this->route('bundle'));
            $pricingMethod = $this->effectiveInput('pricing_method', $bundle?->pricing_method);
            $fixedPrice = $this->effectiveInput('fixed_price', $bundle?->fixed_price);
            if ($pricingMethod === 'fixed' && $fixedPrice === null) {
                $validator->errors()->add('fixed_price', 'A fixed-price bundle requires fixed_price.');
            }
            $bundleProductId = (int) $this->effectiveInput('product_id', $bundle?->product_id);
            $keys = [];
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $key = implode(':', [(int) ($item['product_id'] ?? 0), (int) ($item['product_variant_id'] ?? 0)]);
                if (isset($keys[$key])) {
                    $validator->errors()->add("items.$index.product_variant_id", 'Each product and variant combination may appear only once.');
                }
                $keys[$key] = true;
                if ((int) ($item['product_id'] ?? 0) === $bundleProductId) {
                    $validator->errors()->add("items.$index.product_id", 'A bundle cannot contain itself.');
                }
                if (filled($item['product_variant_id'] ?? null)) {
                    $variant = ProductVariant::query()->find($item['product_variant_id']);
                    if ($variant !== null && (int) $variant->product_id !== (int) ($item['product_id'] ?? 0)) {
                        $validator->errors()->add("items.$index.product_variant_id", 'The variant does not belong to the selected product.');
                    }
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('pricing_method'))) {
            $this->merge(['pricing_method' => strtolower(trim($this->input('pricing_method')))]);
        }
    }
}
