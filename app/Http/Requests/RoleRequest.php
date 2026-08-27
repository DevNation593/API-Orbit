<?php

namespace App\Http\Requests;

class RoleRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_keys' => ['required', 'array', 'max:200'],
            'permission_keys.*' => ['string', 'exists:permissions,key'],
        ];
    }
}
