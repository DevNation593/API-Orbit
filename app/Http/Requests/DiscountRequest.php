<?php

namespace App\Http\Requests;

use App\Models\Discount;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DiscountRequest extends BaseApiRequest
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
            'code' => [$required, 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('discounts', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($this->route('discount'))],
            'name' => [$required, 'string', 'max:160'],
            'type' => [$required, Rule::in(['percentage', 'fixed'])],
            'value' => [$required, 'decimal:0,6', 'gt:0'],
            'minimum_subtotal' => ['nullable', 'decimal:0,6', 'min:0'],
            'maximum_discount' => ['nullable', 'decimal:0,6', 'gt:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
            'conditions' => ['nullable', 'array', 'max:100'],
            'conditions.*.field' => ['required_with:conditions', 'string', 'regex:/^[A-Za-z0-9_.-]{1,190}$/'],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(['eq', 'neq', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_null', 'not_null', 'is_empty', 'is_not_empty'])],
            'conditions.*.value' => ['nullable'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $discount = $this->isMethod('post') ? null : Discount::query()->find($this->route('discount'));
            $type = $this->effectiveInput('type', $discount?->type);
            $value = $this->effectiveInput('value', $discount?->value);
            $currencyId = $this->effectiveInput('currency_id', $discount?->currency_id);
            if ($type === 'percentage' && is_numeric($value) && bccomp((string) $value, '100', 6) === 1) {
                $validator->errors()->add('value', 'A percentage discount cannot exceed 100.');
            }
            if ($type === 'fixed' && blank($currencyId)) {
                $validator->errors()->add('currency_id', 'A fixed discount requires a currency.');
            }
            $startsAt = $this->effectiveInput('starts_at', $discount?->starts_at);
            $endsAt = $this->effectiveInput('ends_at', $discount?->ends_at);
            if (! $validator->errors()->hasAny(['starts_at', 'ends_at']) && filled($startsAt) && filled($endsAt)
                && strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['code', 'type'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = $field === 'code' ? strtoupper(trim($this->input($field))) : strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
