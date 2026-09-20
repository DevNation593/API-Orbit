<?php

namespace App\Http\Requests;

class SupportQueueRequest extends SupportCategoryRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'sla_policy_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'escalation_agent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
