<?php

namespace App\Http\Requests;

use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PriceListRequest extends BaseApiRequest
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
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'name' => [$required, 'string', 'max:160', Rule::unique('price_lists', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('priceList'))],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'conditions' => ['nullable', 'array', 'max:100'],
            'items' => [$required, 'array', 'max:2000'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true))],
            'items.*.minimum_quantity' => ['sometimes', 'decimal:0,6', 'gt:0'],
            'items.*.unit_price' => ['required', 'decimal:0,6', 'min:0'],
            'items.*.compare_at_price' => ['nullable', 'decimal:0,6', 'min:0'],
            'items.*.starts_at' => ['nullable', 'date'],
            'items.*.ends_at' => ['nullable', 'date'],
            'items.*.active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $priceList = $this->isMethod('post') ? null : PriceList::query()->find($this->route('priceList'));
            $startsAt = $this->effectiveInput('starts_at', $priceList?->starts_at);
            $endsAt = $this->effectiveInput('ends_at', $priceList?->ends_at);
            if (! $validator->errors()->hasAny(['starts_at', 'ends_at']) && filled($startsAt) && filled($endsAt)
                && strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }
            $keys = [];
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $key = implode(':', [(int) ($item['product_id'] ?? 0), (int) ($item['product_variant_id'] ?? 0), (string) ($item['minimum_quantity'] ?? '1')]);
                if (isset($keys[$key])) {
                    $validator->errors()->add("items.$index.minimum_quantity", 'The product, variant and quantity break must be unique.');
                }
                $keys[$key] = true;
                if (filled($item['product_variant_id'] ?? null)) {
                    $variant = ProductVariant::query()->find($item['product_variant_id']);
                    if ($variant !== null && (int) $variant->product_id !== (int) ($item['product_id'] ?? 0)) {
                        $validator->errors()->add("items.$index.product_variant_id", 'The variant does not belong to the selected product.');
                    }
                }
                if (filled($item['starts_at'] ?? null) && filled($item['ends_at'] ?? null) && strtotime((string) $item['ends_at']) <= strtotime((string) $item['starts_at'])) {
                    $validator->errors()->add("items.$index.ends_at", 'The item end must be after its start.');
                }
            }
        }];
    }
}
