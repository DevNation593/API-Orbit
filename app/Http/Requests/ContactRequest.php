<?php

namespace App\Http\Requests;

class ContactRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'first_name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'status' => ['sometimes', 'string', 'max:40'],
            'custom_fields' => ['sometimes', 'array'],
            'organization_ids' => ['sometimes', 'array', 'max:50'],
            'organization_ids.*' => ['integer', 'exists:organizations,id'],
        ];
    }
}
