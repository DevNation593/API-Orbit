<?php

namespace App\Http\Requests;

class ActivityRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', $this->memberRule()],
            'type' => [$this->isMethod('get') ? 'sometimes' : 'required', 'in:call,meeting,email,note,status_change,automation,system'],
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:20000'],
            'activityable_type' => ['nullable', 'string', 'max:120', 'required_with:activityable_id'],
            'activityable_id' => ['nullable', 'string', 'max:120', 'required_with:activityable_type'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
