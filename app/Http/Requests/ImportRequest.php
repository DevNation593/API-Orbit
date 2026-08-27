<?php

namespace App\Http\Requests;

class ImportRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'in:contacts,organizations,leads'],
            'file' => ['required', 'file', 'max:51200', 'mimes:csv,txt,xlsx'],
            'mapping' => ['nullable', 'array', 'max:100'],
            'mapping.*' => ['nullable', 'string', 'max:120', 'regex:/^(first_name|last_name|email|phone|source|name|legal_name|website|status|score|custom_fields\.[a-z][a-z0-9_]{1,79})$/'],
        ];
    }
}
