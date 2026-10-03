<?php

namespace App\Http\Requests;

class QuoteSendRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quotes.send') === true;
    }

    public function rules(): array
    {
        return [
            'recipient_email' => ['nullable', 'email:rfc', 'max:190'],
            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
