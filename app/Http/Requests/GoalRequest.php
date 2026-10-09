<?php

namespace App\Http\Requests;

use App\Models\Goal;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GoalRequest extends BaseApiRequest
{
    public const METRICS = ['revenue', 'deals_won', 'deals_created', 'calls', 'meetings', 'new_customers', 'quotes', 'activities'];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('goals.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'name' => [$required, 'string', 'max:190'],
            'metric' => [$required, Rule::in(self::METRICS)],
            'period_type' => [$required, Rule::in(['monthly', 'quarterly', 'yearly', 'custom'])],
            'starts_at' => [$required, 'date_format:Y-m-d'],
            'ends_at' => [$required, 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'completed', 'cancelled'])],
            'settings' => ['nullable', 'array', 'max:100'],
            'targets' => ['sometimes', 'array', 'between:1,500'],
            'targets.*.target_type' => ['required', Rule::in(['tenant', 'user', 'team', 'branch', 'territory', 'product', 'industry'])],
            'targets.*.target_id' => ['nullable', 'integer', 'min:1'],
            'targets.*.target_key' => ['nullable', 'string', 'max:190'],
            'targets.*.target_value' => ['required', 'decimal:0,6', 'min:0'],
            'targets.*.weight' => ['sometimes', 'decimal:0,6', 'gt:0'],
            'targets.*.settings' => ['nullable', 'array', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $goal = $this->isMethod('post') ? null : Goal::query()->find($this->route('goal'));
            $metric = $this->effectiveInput('metric', $goal?->metric);
            $currencyId = $this->effectiveInput('currency_id', $goal?->currency_id);
            if ($metric === 'revenue' && blank($currencyId)) {
                $validator->errors()->add('currency_id', 'Revenue goals require a currency.');
            }
            $startsAt = $this->effectiveInput('starts_at', $goal?->starts_at);
            $endsAt = $this->effectiveInput('ends_at', $goal?->ends_at);
            if (! $validator->errors()->hasAny(['starts_at', 'ends_at']) && filled($startsAt) && filled($endsAt)
                && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
                $validator->errors()->add('ends_at', 'The goal end must not be before its start.');
            }
            foreach ((array) $this->input('targets', []) as $index => $target) {
                if (! is_array($target)) {
                    continue;
                }
                $type = $target['target_type'] ?? null;
                if ($type === 'industry' && blank($target['target_key'] ?? null)) {
                    $validator->errors()->add("targets.$index.target_key", 'Industry targets require target_key.');
                } elseif ($type === 'tenant' && filled($target['target_id'] ?? null)) {
                    $validator->errors()->add("targets.$index.target_id", 'Tenant targets do not use target_id.');
                } elseif (! in_array($type, ['tenant', 'industry'], true) && blank($target['target_id'] ?? null)) {
                    $validator->errors()->add("targets.$index.target_id", 'This target type requires target_id.');
                }
            }
        }];
    }
}
