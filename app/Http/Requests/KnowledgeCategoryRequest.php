<?php

namespace App\Http\Requests;

class KnowledgeCategoryRequest extends KnowledgeRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'position' => ['sometimes', 'integer', 'between:0,65535'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->serverControlledRules(),
        ];
    }
}
