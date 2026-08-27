<?php

namespace App\Http\Requests;

class EntityDefinitionRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:160'],
            'label' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:160'],
            'settings' => ['nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
