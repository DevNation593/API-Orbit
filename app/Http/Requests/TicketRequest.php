<?php

namespace App\Http\Requests;

use App\Support\SupportCatalog;
use Illuminate\Validation\Rule;

class TicketRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $rules = [
            'subject' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::in(SupportCatalog::PRIORITIES)],
            'queue_id' => $creating ? ['required', 'integer', 'min:1'] : ['missing'],
            'assigned_agent_id' => $creating ? ['sometimes', 'nullable', 'integer', 'min:1'] : ['missing'],
            'idempotency_key' => $creating ? ['required', 'string', 'min:1', 'max:120'] : ['missing'],
        ];
        foreach (['category_id', 'contact_id', 'organization_id', 'conversation_id'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:1'];
        }
        foreach ([
            'id', 'tenant_id', 'status', 'created_by', 'created_at', 'updated_at', 'resolution_summary',
            'first_response_at', 'resolved_at', 'closed_at', 'payload_hash', 'snapshot', 'sla',
            'first_response_due_at', 'resolution_due_at', 'first_response_breached', 'resolution_breached',
        ] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
