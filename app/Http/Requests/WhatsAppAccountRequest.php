<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class WhatsAppAccountRequest extends BaseApiRequest
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
                fn ($query) => $query->where('tenant_id', $tenantId)->where('provider', 'whatsapp'),
            )],
            'business_account_id' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'phone_number_id' => [$required, 'string', 'max:190', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'display_phone_number' => ['nullable', 'string', 'max:80'],
            'verify_token' => [$required, 'string', 'min:16', 'max:190'],
            'settings' => ['nullable', 'array', 'max:50'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'disconnected', 'error'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtolower(trim($this->input('status')))]);
        }
    }
}
