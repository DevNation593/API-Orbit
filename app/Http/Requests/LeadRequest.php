<?php

namespace App\Http\Requests;

class LeadRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'status' => ['sometimes', 'string', 'max:40'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
