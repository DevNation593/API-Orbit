<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MeetingBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date', 'after:now'],
            'timezone' => ['required', 'timezone:all'],
            'invitee_name' => ['required', 'string', 'max:190'],
            'invitee_email' => ['required', 'email:rfc', 'max:190'],
            'invitee_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'idempotency_key' => ['nullable', 'string', 'between:8,190', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
        if (is_string($this->input('invitee_email'))) {
            $this->merge(['invitee_email' => mb_strtolower(trim($this->input('invitee_email')))]);
        }
    }
}
