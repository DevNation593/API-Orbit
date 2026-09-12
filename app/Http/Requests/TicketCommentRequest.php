<?php

namespace App\Http\Requests;

use App\Support\SupportCatalog;
use Illuminate\Validation\Rule;

class TicketCommentRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $rules = [
            'visibility' => ['required', Rule::in(SupportCatalog::VISIBILITIES)],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:120'],
        ];
        foreach (['id', 'tenant_id', 'ticket_id', 'author_user_id', 'created_at', 'updated_at', 'payload_hash', 'first_response_at', 'sla', 'snapshot', 'status'] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
