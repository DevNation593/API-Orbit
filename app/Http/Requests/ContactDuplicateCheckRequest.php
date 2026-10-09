<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ContactDuplicateCheckRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:241'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'identification' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:190'],
            'exclude_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(
                fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId())->whereNull('deleted_at'),
            )],
            'minimum_score' => ['sometimes', 'integer', 'between:1,100'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = ['first_name', 'last_name', 'name', 'email', 'phone', 'identification', 'tax_id', 'company'];
            if (! collect($fields)->contains(fn (string $field): bool => trim((string) $this->input($field)) !== '')) {
                $validator->errors()->add('criteria', 'Provide at least one duplicate detection criterion.');
            }
        }];
    }
}
