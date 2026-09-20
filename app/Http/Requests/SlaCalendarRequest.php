<?php

namespace App\Http\Requests;

class SlaCalendarRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:160'],
            'mode' => [$required, 'string'],
            'timezone' => [$required, 'string'],
            'weekly_schedule' => ['sometimes', 'array'],
            'holidays' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
