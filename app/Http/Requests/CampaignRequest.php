<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class CampaignRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('campaigns.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('patch') ? 'sometimes' : 'required';
        $tenant = app(TenantContext::class)->requireId();

        return [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'audience_id' => [$required, 'integer', Rule::exists('audiences', 'id')->where('tenant_id', $tenant)],
            'sender_user_id' => [$required, 'integer', $this->memberRule()],
            'currency' => [$required, 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'cost' => ['sometimes', 'string', 'regex:/^\\d{1,18}(\\.\\d{1,6})?$/'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'email' => [$required, 'array:inbox_channel_id,subject,body,body_html'],
            'email.inbox_channel_id' => ['required_with:email', 'integer', Rule::exists('inbox_channels', 'id')->where('tenant_id', $tenant)->where('channel', 'email')->where('status', 'active')],
            'email.subject' => ['required_with:email', 'string', 'max:255'],
            'email.body' => ['required_with:email', 'string', 'max:100000'],
            'email.body_html' => ['nullable', 'string', 'max:100000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }
}
