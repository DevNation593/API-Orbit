<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MergeContactRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'duplicate_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where(
                fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId())->whereNull('deleted_at'),
            )],
            'field_overrides' => ['sometimes', 'array'],
            'field_overrides.first_name' => ['sometimes', 'string', 'max:120'],
            'field_overrides.last_name' => ['nullable', 'string', 'max:120'],
            'field_overrides.email' => ['nullable', 'email:rfc', 'max:190'],
            'field_overrides.phone' => ['nullable', 'string', 'max:50'],
            'field_overrides.owner_id' => ['nullable', 'integer', $this->memberRule()],
            'field_overrides.status' => ['sometimes', 'string', 'max:40'],
            'field_overrides.custom_fields' => ['sometimes', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ((int) $this->input('duplicate_id') === (int) $this->route('contact')) {
                $validator->errors()->add('duplicate_id', 'A contact cannot be merged into itself.');
            }
        }];
    }
}
