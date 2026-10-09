<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MeetingControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'manage_token' => ['required', 'string', 'max:190'],
            'starts_at' => ['sometimes', 'date', 'after:now'],
            'timezone' => ['sometimes', 'timezone:all'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
