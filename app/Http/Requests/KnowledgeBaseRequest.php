<?php

namespace App\Http\Requests;

class KnowledgeBaseRequest extends KnowledgeRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_public' => ['sometimes', 'boolean'],
            ...$this->serverControlledRules(),
        ];
    }
}
