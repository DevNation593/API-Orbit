<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MergeOrganizationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'duplicate_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where(
                fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId())->whereNull('deleted_at'),
            )],
            'field_overrides' => ['sometimes', 'array'],
            'field_overrides.name' => ['sometimes', 'string', 'max:190'],
            'field_overrides.legal_name' => ['nullable', 'string', 'max:190'],
            'field_overrides.email' => ['nullable', 'email:rfc', 'max:190'],
            'field_overrides.phone' => ['nullable', 'string', 'max:50'],
            'field_overrides.website' => ['nullable', 'url', 'max:255'],
            'field_overrides.owner_id' => ['nullable', 'integer', $this->memberRule()],
            'field_overrides.custom_fields' => ['sometimes', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $targetId = $this->route('organization') ?? $this->route('company');
            if ((int) $this->input('duplicate_id') === (int) $targetId) {
                $validator->errors()->add('duplicate_id', 'A company cannot be merged into itself.');
            }
        }];
    }
}
