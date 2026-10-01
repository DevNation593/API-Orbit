<?php

namespace App\Http\Requests;

class PublicQuoteDecisionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'comment' => ['nullable', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'string', 'between:8,190'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');
        if (is_string($key) && trim($key) !== '') {
            $this->merge(['idempotency_key' => trim($key)]);
        }
    }
}
