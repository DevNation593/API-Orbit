<?php

namespace App\Http\Requests;

class EntityRelationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'relation_type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,79}$/'],
            'from_type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:120'],
            'from_id' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:120'],
            'to_type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:120'],
            'to_id' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
