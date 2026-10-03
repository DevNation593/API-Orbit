<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PlaybookExecutionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('playbooks.execute') === true;
    }

    public function rules(): array
    {
        return [
            'playbook_id' => ['required', 'integer', 'min:1'],
            'entity_type' => ['required', Rule::in(['contact', 'organization', 'lead', 'deal'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'assigned_to' => ['nullable', 'integer', $this->memberRule()],
            'metadata' => ['nullable', 'array', 'max:100'],
        ];
    }
}
