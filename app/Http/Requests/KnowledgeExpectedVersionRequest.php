<?php

namespace App\Http\Requests;

class KnowledgeExpectedVersionRequest extends KnowledgeRequest
{
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            ...$this->serverControlledRules(),
        ];
    }
}
