<?php

namespace App\Http\Requests;

class TenantRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:80'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
