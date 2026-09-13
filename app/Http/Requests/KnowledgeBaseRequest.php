<?php

namespace App\Http\Requests;

class KnowledgeBaseRequest extends BaseApiRequest
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

    private function serverControlledRules(): array
    {
        return array_fill_keys([
            'id', 'tenant_id', 'public_id', 'normalized_name', 'created_by',
            'created_at', 'updated_at', 'slug',
        ], ['missing']);
    }
}
