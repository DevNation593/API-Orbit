<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ConversationIndexRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'channel' => ['sometimes', Rule::in(InboxCatalog::CHANNELS)],
            'status' => ['sometimes', Rule::in(InboxCatalog::CONVERSATION_STATUSES)],
            'owner' => ['sometimes', 'integer', $this->memberRule()],
            'team' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'contact' => ['sometimes', 'integer', Rule::exists('contacts', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            )],
            'inbox_id' => ['sometimes', 'integer', Rule::exists('inboxes', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'unread' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['channel', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
