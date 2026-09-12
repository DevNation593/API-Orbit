<?php

namespace App\Http\Requests;

use App\Support\SupportCatalog;
use Illuminate\Validation\Rule;

class TicketStatusRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $rules = [
            'status' => ['required', Rule::in(SupportCatalog::STATUSES)],
            'resolution_summary' => ['sometimes', 'nullable', 'string', 'min:1', 'max:5000'],
            'reason' => ['sometimes', 'nullable', 'string', 'min:1', 'max:2000'],
        ];
        foreach ([
            'id', 'tenant_id', 'created_by', 'created_at', 'updated_at', 'first_response_at', 'resolved_at',
            'closed_at', 'payload_hash', 'snapshot', 'sla', 'queue_id', 'assigned_agent_id', 'idempotency_key',
            'first_response_due_at', 'resolution_due_at', 'first_response_breached', 'resolution_breached',
        ] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
