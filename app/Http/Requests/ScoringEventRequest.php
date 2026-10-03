<?php

namespace App\Http\Requests;

class ScoringEventRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('lead_scoring.manage') === true;
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'event_key' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'metadata' => ['nullable', 'array', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('event_type'))) {
            $this->merge(['event_type' => mb_strtolower(trim($this->input('event_type')))]);
        }
    }
}
