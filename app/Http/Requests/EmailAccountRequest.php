<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class EmailAccountRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'inbox_channel_id' => [$required, 'integer', Rule::exists('inbox_channels', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'integration_id' => [$required, 'integer', Rule::exists('integrations', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'user_id' => ['nullable', 'integer', $this->memberRule()],
            'provider' => [$required, Rule::in(['google', 'microsoft', 'smtp'])],
            'email_address' => [$required, 'email:rfc', 'max:190'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'signature_html' => ['nullable', 'string', 'max:100000'],
            'settings' => ['nullable', 'array', 'max:50'],
            'settings.track_opens' => ['sometimes', 'boolean'],
            'settings.track_clicks' => ['sometimes', 'boolean'],
            'settings.sync_enabled' => ['sometimes', 'boolean'],
            'settings.max_attachment_bytes' => ['sometimes', 'integer', 'between:1,26214400'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'disconnected', 'error'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['provider', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower(trim($this->input($field)));
            }
        }
        if (is_string($this->input('email_address'))) {
            $data['email_address'] = mb_strtolower(trim($this->input('email_address')));
        }
        $this->merge($data);
    }
}
