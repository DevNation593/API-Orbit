<?php

namespace App\Http\Requests;

class KnowledgeTagRequest extends KnowledgeRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->serverControlledRules(),
        ];
    }
}
