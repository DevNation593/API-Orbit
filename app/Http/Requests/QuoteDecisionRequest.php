<?php

namespace App\Http\Requests;

class QuoteDecisionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quotes.accept') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'comment' => ['nullable', 'string', 'max:10000'],
            'idempotency_key' => ['nullable', 'string', 'between:8,190'],
        ];
    }
}
