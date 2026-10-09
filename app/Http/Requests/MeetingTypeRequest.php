<?php

namespace App\Http\Requests;

use App\Models\MeetingType;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MeetingTypeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('meetings.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('meeting_types', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('meetingType')),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'host_user_id' => ['nullable', 'integer', $this->memberRule()],
            'duration_minutes' => [$required, 'integer', 'between:5,1440'],
            'timezone' => [$required, 'timezone:all'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'minimum_notice_minutes' => ['sometimes', 'integer', 'between:0,525600'],
            'maximum_days_ahead' => ['sometimes', 'integer', 'between:1,730'],
            'assignment_strategy' => ['sometimes', Rule::in(['fixed', 'round_robin'])],
            'location_type' => ['sometimes', Rule::in(['google_meet', 'microsoft_teams', 'zoom', 'phone', 'in_person', 'custom'])],
            'location_details' => ['nullable', 'array', 'max:20'],
            'location_details.label' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'array', 'max:30'],
            'settings.slot_interval_minutes' => ['sometimes', 'integer', 'between:5,1440'],
            'settings.create_lead' => ['sometimes', 'boolean'],
            'settings.calendar_provider' => ['sometimes', Rule::in(['google', 'microsoft', 'zoom'])],
            'active' => ['sometimes', 'boolean'],
            'availability' => [$required, 'array', 'between:1,100'],
            'availability.*' => ['array', 'max:15'],
            'availability.*.user_id' => ['nullable', 'integer', $this->memberRule()],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required', 'date_format:H:i'],
            'availability.*.end_time' => ['required', 'date_format:H:i'],
            'availability.*.timezone' => ['nullable', 'timezone:all'],
            'availability.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'availability.*.valid_until' => ['nullable', 'date_format:Y-m-d'],
            'availability.*.active' => ['sometimes', 'boolean'],
            'exclusions' => ['sometimes', 'array', 'max:500'],
            'exclusions.*' => ['array', 'max:10'],
            'exclusions.*.user_id' => ['nullable', 'integer', $this->memberRule()],
            'exclusions.*.starts_at' => ['required', 'date'],
            'exclusions.*.ends_at' => ['required', 'date'],
            'exclusions.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $existing = $this->isMethod('post') ? null : MeetingType::query()
                ->with('availabilityRules')->find($this->route('meetingType'));
            $strategy = $this->input('assignment_strategy', $existing?->assignment_strategy ?? 'fixed');
            $hostUserId = $this->input('host_user_id', $existing?->host_user_id);
            $availability = $this->has('availability')
                ? (array) $this->input('availability', [])
                : ($existing?->availabilityRules?->map(fn ($rule) => $rule->toArray())->all() ?? []);
            if ($strategy === 'fixed' && blank($hostUserId)) {
                $validator->errors()->add('host_user_id', 'Fixed assignment requires a host user.');
            }
            if ($strategy === 'round_robin' && collect($availability)->filter(fn ($rule) => is_array($rule) && filled($rule['user_id'] ?? null))->isEmpty()) {
                $validator->errors()->add('availability', 'Round-robin assignment requires user-specific availability rules.');
            }
            foreach ($availability as $index => $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                if (($rule['start_time'] ?? '') >= ($rule['end_time'] ?? '')) {
                    $validator->errors()->add("availability.$index.end_time", 'End time must be after start time.');
                }
                if (filled($rule['valid_from'] ?? null) && filled($rule['valid_until'] ?? null)
                    && $rule['valid_from'] > $rule['valid_until']) {
                    $validator->errors()->add("availability.$index.valid_until", 'The validity end must not precede the start.');
                }
            }
            foreach ((array) $this->input('exclusions', []) as $index => $exclusion) {
                if (is_array($exclusion) && filled($exclusion['starts_at'] ?? null) && filled($exclusion['ends_at'] ?? null)
                    && strtotime((string) $exclusion['ends_at']) <= strtotime((string) $exclusion['starts_at'])) {
                    $validator->errors()->add("exclusions.$index.ends_at", 'Exclusion end must be after its start.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['assignment_strategy', 'location_type'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = mb_strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
