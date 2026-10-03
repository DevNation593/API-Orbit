<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use Illuminate\Validation\Rule;

class ConversationStatusRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(InboxCatalog::CONVERSATION_STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtolower(trim($this->input('status')))]);
        }
    }
}
