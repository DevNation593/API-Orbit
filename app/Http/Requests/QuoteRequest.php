<?php

namespace App\Http\Requests;

use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuoteRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->isMethod('post') ? 'quotes.create' : 'quotes.update') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();
        $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));

        return [
            'deal_id' => ['nullable', 'integer', $tenantExists('deals')],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'title' => ['nullable', 'string', 'max:190'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:50000'],
            'terms' => ['nullable', 'string', 'max:50000'],
            'billing_address' => ['nullable', 'array', 'max:30'],
            'shipping_address' => ['nullable', 'array', 'max:30'],
            'metadata' => ['nullable', 'array', 'max:100'],
            'discount_codes' => ['sometimes', 'array', 'max:20'],
            'discount_codes.*' => ['string', 'max:80', 'distinct'],
            'items' => [$required, 'array', 'between:1,500'],
            'items.*' => ['array', 'max:20'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'items.*.name' => ['nullable', 'string', 'max:190'],
            'items.*.description' => ['nullable', 'string', 'max:10000'],
            'items.*.quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'decimal:0,6', 'min:0'],
            'items.*.taxable' => ['sometimes', 'boolean'],
            'items.*.tax_ids' => ['sometimes', 'array', 'max:20'],
            'items.*.tax_ids.*' => ['integer', 'distinct', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'items.*.discount_ids' => ['sometimes', 'array', 'max:20'],
            'items.*.discount_ids.*' => ['integer', 'distinct', Rule::exists('discounts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'items.*.metadata' => ['nullable', 'array', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (blank($item['product_id'] ?? null) && (blank($item['name'] ?? null) || ! array_key_exists('unit_price', $item))) {
                    $validator->errors()->add("items.$index.product_id", 'A custom line requires both name and unit_price.');
                }
                if (filled($item['product_variant_id'] ?? null)) {
                    $variant = ProductVariant::query()->find($item['product_variant_id']);
                    if ($variant !== null && (int) $variant->product_id !== (int) ($item['product_id'] ?? 0)) {
                        $validator->errors()->add("items.$index.product_variant_id", 'The variant does not belong to the selected product.');
                    }
                }
                if (array_key_exists('unit_price', $item) && ! $this->user()?->hasPermission('pricing.manage')) {
                    $validator->errors()->add("items.$index.unit_price", 'Manual prices require pricing.manage permission.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('discount_codes'))) {
            $this->merge(['discount_codes' => array_map(fn ($code) => is_string($code) ? strtoupper(trim($code)) : $code, $this->input('discount_codes'))]);
        }
    }
}
