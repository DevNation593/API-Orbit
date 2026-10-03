<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class InboxChannelRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'channel' => [$required, Rule::in(InboxCatalog::CHANNELS)],
            'name' => [$required, 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:190'],
            'external_identifier' => ['nullable', 'string', 'max:190'],
            'integration_id' => ['nullable', 'integer', Rule::exists('integrations', 'id')->where(
                fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId()),
            )],
            'settings' => ['nullable', 'array', 'max:50'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
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
