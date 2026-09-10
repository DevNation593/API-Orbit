<?php

namespace App\Http\Requests;

class SlaPolicyRequest extends SupportCategoryRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'calendar_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pause_on_waiting_customer' => ['sometimes', 'boolean'],
            'rules' => [$this->isMethod('post') ? 'required' : 'sometimes', 'array', 'list', 'size:4'],
        ];
    }
}
