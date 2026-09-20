<?php

namespace App\Services;

use App\Models\SlaBusinessCalendar;
use App\Models\SlaExecution;
use App\Models\SlaPolicy;
use App\Support\AuditService;
use App\Support\SupportCatalog;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SlaConfigurationService
{
    public function __construct(
        private readonly SlaCalendarService $calendars,
        private readonly SupportConfigurationService $support,
        private readonly AuditService $audit,
    ) {}

    public function saveCalendar(array $data, ?SlaBusinessCalendar $calendar = null): SlaBusinessCalendar
    {
        return DB::transaction(function () use ($data, $calendar): SlaBusinessCalendar {
            $tenantId = app(TenantContext::class)->requireId();
            $calendar = $calendar === null ? new SlaBusinessCalendar : SlaBusinessCalendar::forTenant($tenantId)->lockForUpdate()->findOrFail($calendar->id);
            $old = $calendar->exists ? $this->calendarValues($calendar) : null;
            $fields = ['name', 'mode', 'timezone', 'weekly_schedule', 'holidays', 'is_active'];
            $values = array_replace(
                ['mode' => 'ALWAYS', 'timezone' => 'UTC', 'weekly_schedule' => [], 'holidays' => [], 'is_active' => true],
                $calendar->exists ? $calendar->only($fields) : [],
                Arr::only($data, $fields),
            );
            $values['weekly_schedule'] ??= [];
            $values['holidays'] ??= [];
            $basic = Validator::make($values, [
                'name' => ['required', 'string', 'min:1', 'max:160'],
                'is_active' => ['required', 'boolean'],
            ])->validate();
            $calendar->fill($basic + $this->calendars->normalize($values))->save();
            $calendar->refresh();
            $this->audit->record($old === null ? 'create' : 'update', $calendar, oldValues: $old, newValues: $this->calendarValues($calendar));

            return $calendar;
        });
    }

    public function savePolicy(array $data, ?SlaPolicy $policy = null): SlaPolicy
    {
        return DB::transaction(function () use ($data, $policy): SlaPolicy {
            $tenantId = app(TenantContext::class)->requireId();
            $policy = $policy === null ? new SlaPolicy : SlaPolicy::forTenant($tenantId)->lockForUpdate()->findOrFail($policy->id);
            $old = $policy->exists ? $this->policyValues($policy) : null;
            $fields = ['name', 'description', 'calendar_id', 'pause_on_waiting_customer', 'is_active'];
            $values = array_replace(
                ['description' => null, 'calendar_id' => null, 'pause_on_waiting_customer' => true, 'is_active' => true],
                $policy->exists ? $policy->only($fields) : [],
                Arr::only($data, $fields),
            );
            $values = Validator::make($values, [
                'name' => ['required', 'string', 'min:1', 'max:160'],
                'description' => ['nullable', 'string', 'max:5000'],
                'calendar_id' => ['nullable', 'integer', 'min:1'],
                'pause_on_waiting_customer' => ['required', 'boolean'],
                'is_active' => ['required', 'boolean'],
            ])->validate();
            $validateCalendar = ! $policy->exists || array_key_exists('calendar_id', $data)
                || (! $policy->is_active && (bool) $values['is_active']);
            if ($validateCalendar && $values['calendar_id'] !== null) {
                $calendar = SlaBusinessCalendar::whereKey($values['calendar_id'])->lockForUpdate()->first();
                if ($calendar === null || ! $calendar->is_active) {
                    throw ValidationException::withMessages(['calendar_id' => 'Select an active SLA calendar in this tenant.']);
                }
            }
            if (! $policy->exists && ! array_key_exists('rules', $data)) {
                throw ValidationException::withMessages(['rules' => 'Provide one rule for every priority.']);
            }
            $rules = array_key_exists('rules', $data) ? $this->normalizeRules($data['rules']) : null;
            $policy->fill($values)->save();
            if ($rules !== null) {
                $policy->rules()->delete();
                $policy->rules()->createMany($rules);
            }
            $policy->refresh()->load('rules');
            $this->audit->record($old === null ? 'create' : 'update', $policy, oldValues: $old, newValues: $this->policyValues($policy));

            return $policy;
        });
    }

    public function delete(Model $model): void
    {
        abort_unless($model instanceof SlaPolicy || $model instanceof SlaBusinessCalendar, 409, 'Only SLA configuration can be deleted.');
        DB::transaction(function () use ($model): void {
            $model = $model::forTenant(app(TenantContext::class)->requireId())->lockForUpdate()->findOrFail($model->getKey());
            if ($model instanceof SlaPolicy) {
                abort_if(SlaExecution::where('snapshot->policy_id', $model->id)->exists(), 409, 'Historical SLA executions still reference this policy.');
                $model->rules()->delete();
            }
            // A queue/calendar FK conflict or audit failure also rolls back the rule deletion.
            $this->support->delete($model);
        });
    }

    private function normalizeRules(mixed $rules): array
    {
        $rules = Validator::make(['rules' => $rules], [
            'rules' => ['required', 'array', 'list', 'size:4'],
            'rules.*.priority' => ['required', Rule::in(SupportCatalog::PRIORITIES), 'distinct:strict'],
            'rules.*.first_response_minutes' => ['required', 'integer', 'between:1,525600'],
            'rules.*.resolution_minutes' => ['required', 'integer', 'between:1,525600'],
        ])->validate()['rules'];
        $normalized = [];
        foreach ($rules as $index => $rule) {
            if ((int) $rule['first_response_minutes'] > (int) $rule['resolution_minutes']) {
                throw ValidationException::withMessages(['rules.'.$index.'.first_response_minutes' => 'First response cannot exceed resolution.']);
            }
            $normalized[$rule['priority']] = [
                'priority' => $rule['priority'],
                'first_response_minutes' => (int) $rule['first_response_minutes'],
                'resolution_minutes' => (int) $rule['resolution_minutes'],
            ];
        }

        return array_map(fn (string $priority): array => $normalized[$priority], SupportCatalog::PRIORITIES);
    }

    private function calendarValues(SlaBusinessCalendar $calendar): array
    {
        return $calendar->only(['id', 'tenant_id', 'name', 'mode', 'timezone', 'weekly_schedule', 'holidays', 'is_active']);
    }

    private function policyValues(SlaPolicy $policy): array
    {
        return $policy->only(['id', 'tenant_id', 'name', 'calendar_id', 'pause_on_waiting_customer', 'is_active'])
            + ['rules' => $policy->rules->map(fn ($rule) => $rule->only(['priority', 'first_response_minutes', 'resolution_minutes']))->all()];
    }
}
