<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ConversationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'inbox_id' => ['required', 'integer', Rule::exists('inboxes', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->where('status', 'active'),
            )],
            'inbox_channel_id' => ['required', 'integer', Rule::exists('inbox_channels', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->where('status', 'active'),
            )],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            )],
            'assigned_user_id' => ['nullable', 'integer', $this->memberRule()],
            'assigned_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'subject' => ['nullable', 'string', 'max:255'],
            'external_identifier' => ['nullable', 'string', 'max:190'],
            'status' => ['sometimes', Rule::in(InboxCatalog::CONVERSATION_STATUSES)],
            'priority' => ['sometimes', Rule::in(InboxCatalog::PRIORITIES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['status', 'priority'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
