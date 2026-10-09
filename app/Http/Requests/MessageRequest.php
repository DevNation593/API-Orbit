<?php

namespace App\Http\Requests;

use App\Models\Message;
use App\Support\InboxCatalog;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MessageRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'type' => ['sometimes', Rule::in(InboxCatalog::MESSAGE_TYPES)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'content' => ['nullable', 'array', 'max:100'],
            'reply_to_id' => ['nullable', 'integer'],
            'file_ids' => ['sometimes', 'array', 'max:20'],
            'file_ids.*' => ['integer', 'distinct', Rule::exists('file_records', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'client_message_id' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'is_internal' => ['sometimes', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (blank($this->input('body')) && ! is_array($this->input('content')) && $this->input('file_ids', []) === []) {
                $validator->errors()->add('body', 'A body, structured content, or attachment is required.');
            }
            $replyTo = $this->input('reply_to_id');
            $conversationId = (int) $this->route('conversation');
            if ($replyTo !== null && ! Message::query()->whereKey($replyTo)->where('conversation_id', $conversationId)->exists()) {
                $validator->errors()->add('reply_to_id', 'The reply target does not belong to this conversation.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => strtolower(trim($this->input('type')))]);
        }
    }
}
