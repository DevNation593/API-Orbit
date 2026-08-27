<?php

namespace App\Http\Requests;

class AutomationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'event_type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{2,79}$/'],
            'config' => ['required', 'array'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
