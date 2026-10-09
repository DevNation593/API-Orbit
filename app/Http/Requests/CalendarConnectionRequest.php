<?php

namespace App\Http\Requests;

use App\Models\CalendarConnection;
use App\Models\Integration;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarConnectionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('calendar_connections.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'user_id' => [$required, 'integer', $this->memberRule()],
            'integration_id' => [$required, 'integer', Rule::exists('integrations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'provider' => [$required, Rule::in(['google', 'microsoft', 'zoom'])],
            'external_calendar_id' => ['sometimes', 'string', 'max:190'],
            'timezone' => ['sometimes', 'timezone:all'],
            'settings' => ['nullable', 'array', 'max:30'],
            'settings.schedule_address' => ['nullable', 'email:rfc', 'max:190'],
            'settings.send_updates' => ['sometimes', Rule::in(['all', 'externalOnly', 'none'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'error'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $existing = $this->isMethod('post') ? null : CalendarConnection::query()->find($this->route('connection'));
            $integrationId = $this->input('integration_id', $existing?->integration_id);
            $provider = $this->input('provider', $existing?->provider);
            if ($integrationId !== null && $provider !== null) {
                $integration = Integration::query()->find($integrationId);
                if ($integration !== null && ($integration->provider !== $provider || $integration->status !== 'active')) {
                    $validator->errors()->add('integration_id', 'The integration must be an active connection for the selected provider.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['provider', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = mb_strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
