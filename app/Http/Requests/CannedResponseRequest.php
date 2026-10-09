<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use Illuminate\Validation\Rule;

class CannedResponseRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:120'],
            'shortcut' => [$required, 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'body' => [$required, 'string', 'max:100000'],
            'channel' => ['nullable', Rule::in(InboxCatalog::CHANNELS)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['shortcut', 'channel'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
