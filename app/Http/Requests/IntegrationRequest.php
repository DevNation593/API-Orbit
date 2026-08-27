<?php

namespace App\Http\Requests;

class IntegrationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'provider' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,79}$/'],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:160'],
            'credentials' => ['sometimes', 'nullable', 'array', 'max:100'],
            'settings' => ['sometimes', 'nullable', 'array', 'max:100'],
            'status' => ['sometimes', 'in:pending,active,disabled'],
        ];
    }
}
