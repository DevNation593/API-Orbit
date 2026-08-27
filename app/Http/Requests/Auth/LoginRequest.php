<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class LoginRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
