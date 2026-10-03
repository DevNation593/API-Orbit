<?php

namespace App\Http\Requests;

class EntityRelationRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['from_type', 'to_type'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $normalized[$field] = strtolower(trim($this->input($field)));
            }
        }
        if ($this->has('relation_type') && is_string($this->input('relation_type'))) {
            $normalized['relation_type'] = strtolower(trim($this->input('relation_type')));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'relation_type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,79}$/'],
            'from_type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:120'],
            'from_id' => [$this->isMethod('get') ? 'sometimes' : 'required', 'integer', 'min:1'],
            'to_type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:120'],
            'to_id' => [$this->isMethod('get') ? 'sometimes' : 'required', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
