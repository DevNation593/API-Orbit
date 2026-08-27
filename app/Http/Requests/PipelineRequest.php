<?php

namespace App\Http\Requests;

class PipelineRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array', 'max:100'],
            'stages.*.id' => ['sometimes', 'integer', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:120'],
            'stages.*.position' => ['sometimes', 'integer', 'min:0'],
            'stages.*.probability' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'stages.*.color' => ['nullable', 'string', 'max:30'],
            'stages.*.is_won' => ['sometimes', 'boolean'],
            'stages.*.is_lost' => ['sometimes', 'boolean'],
        ];
    }
}
