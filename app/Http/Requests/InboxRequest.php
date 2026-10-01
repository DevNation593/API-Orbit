<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class InboxRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'default_assignee_id' => ['nullable', 'integer', $this->memberRule()],
            'default_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'channels' => ['sometimes', 'array', 'max:20'],
            'channels.*.channel' => ['required', Rule::in(InboxCatalog::CHANNELS)],
            'channels.*.name' => ['required', 'string', 'max:120'],
            'channels.*.address' => ['nullable', 'string', 'max:190'],
            'channels.*.external_identifier' => ['nullable', 'string', 'max:190'],
            'channels.*.integration_id' => ['nullable', 'integer', Rule::exists('integrations', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'channels.*.settings' => ['nullable', 'array', 'max:50'],
            'channels.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('status'))) {
            $data['status'] = strtolower(trim($this->input('status')));
        }
        if (is_array($this->input('channels'))) {
            $data['channels'] = collect($this->input('channels'))->map(function ($channel): mixed {
                if (! is_array($channel)) {
                    return $channel;
                }
                foreach (['channel', 'status'] as $field) {
                    if (is_string($channel[$field] ?? null)) {
                        $channel[$field] = strtolower(trim($channel[$field]));
                    }
                }

                return $channel;
            })->all();
        }
        $this->merge($data);
    }
}
