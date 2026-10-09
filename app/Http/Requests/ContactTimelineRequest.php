<?php

namespace App\Http\Requests;

class ContactTimelineRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'source' => ['sometimes', 'in:activity,audit,quote'],
            'event' => ['sometimes', 'string', 'max:120'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
