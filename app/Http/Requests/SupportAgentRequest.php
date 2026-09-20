<?php

namespace App\Http\Requests;

class SupportAgentRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'user_id' => $this->isMethod('post') ? ['required', 'integer', 'min:1'] : ['missing'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
