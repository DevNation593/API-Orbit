<?php

namespace App\Http\Requests;

class OrganizationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:190'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'custom_fields' => ['sometimes', 'array'],
            'contact_ids' => ['sometimes', 'array', 'max:100'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
        ];
    }
}
