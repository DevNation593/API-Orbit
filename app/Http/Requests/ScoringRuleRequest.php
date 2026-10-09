<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ScoringRuleRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('lead_scoring.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::in(['explicit', 'behavioral', 'negative'])],
            'event_type' => ['nullable', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'points' => [$required, 'integer', 'between:-100000,100000', 'not_in:0'],
            'match_type' => ['sometimes', Rule::in(['all', 'any'])],
            'priority' => ['sometimes', 'integer', 'between:0,65535'],
            'repeatable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'conditions' => [$required, 'array', 'max:50'],
            'conditions.*' => ['array', 'max:10'],
            'conditions.*.field' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z][A-Za-z0-9_.]*$/'],
            'conditions.*.operator' => ['required', Rule::in(LeadRoutingRuleRequest::OPERATORS)],
            'conditions.*.value' => ['nullable'],
            'conditions.*.position' => ['sometimes', 'integer', 'between:0,65535'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $eventType = $this->input('event_type');
            if ($this->input('type') === 'behavioral' && blank($eventType)) {
                $validator->errors()->add('event_type', 'Behavioral scoring rules require an event type.');
            }
            foreach ((array) $this->input('conditions', []) as $index => $condition) {
                $field = is_array($condition) ? (string) ($condition['field'] ?? '') : '';
                if (str_starts_with($field, 'event.') && blank($eventType)) {
                    $validator->errors()->add("conditions.$index.field", 'Event fields require event_type.');
                }
                if (! str_starts_with($field, 'event.') && ! $this->allowedLeadField($field)) {
                    $validator->errors()->add("conditions.$index.field", 'This field cannot be used for lead scoring.');
                }
            }
        }];
    }

    private function allowedLeadField(string $field): bool
    {
        $field = str_starts_with($field, 'lead.') ? substr($field, 5) : $field;

        return str_starts_with($field, 'custom_fields.') || in_array($field, [
            'first_name', 'last_name', 'email', 'phone', 'source', 'capture_origin', 'status', 'score',
            'medium', 'campaign', 'utm_source', 'utm_medium', 'utm_campaign', 'owner_id',
            'organization_id', 'contact_id', 'created_at',
        ], true);
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['type', 'event_type', 'match_type'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = mb_strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
