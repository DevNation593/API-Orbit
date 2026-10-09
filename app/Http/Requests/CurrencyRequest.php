<?php

namespace App\Http\Requests;

use App\Models\Currency;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CurrencyRequest extends BaseApiRequest
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
            'code' => [$required, 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::unique('currencies', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($this->route('currency'))],
            'name' => [$required, 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:12'],
            'decimal_places' => ['sometimes', 'integer', 'between:0,6'],
            'exchange_rate' => ['sometimes', 'decimal:0,10', 'gt:0'],
            'is_base' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $currency = $this->isMethod('post') ? null : Currency::query()->find($this->route('currency'));
            $isBase = array_key_exists('is_base', $this->all())
                ? $this->boolean('is_base')
                : ($currency?->is_base ?? Currency::query()->doesntExist());

            if ($currency?->is_base && array_key_exists('is_base', $this->all()) && ! $this->boolean('is_base')) {
                $validator->errors()->add('is_base', 'Promote another currency instead of demoting the current base currency.');
            }
            if ($isBase && array_key_exists('active', $this->all()) && ! $this->boolean('active')) {
                $validator->errors()->add('active', 'The base currency must remain active.');
            }
            if ($isBase && $this->has('exchange_rate') && bccomp((string) $this->input('exchange_rate'), '1', 10) !== 0) {
                $validator->errors()->add('exchange_rate', 'The base currency exchange rate must be 1.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
