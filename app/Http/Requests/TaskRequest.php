<?php

namespace App\Http\Requests;

class TaskRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['nullable', 'integer', $this->memberRule()],
            'status' => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'due_at' => ['nullable', 'date'],
            'related_type' => ['nullable', 'string', 'max:120', 'required_with:related_id'],
            'related_id' => ['nullable', 'string', 'max:120', 'required_with:related_type'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
