<?php

namespace App\Http\Requests;

class KnowledgeTagRequest extends BaseApiRequest
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

    private function serverControlledRules(): array
    {
        return array_fill_keys([
            'id', 'tenant_id', 'public_id', 'normalized_name', 'created_by',
            'created_at', 'updated_at', 'slug',
        ], ['missing']);
    }
}
