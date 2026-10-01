<?php

namespace App\Http\Requests;

use App\Services\LeadRoutingService;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LeadRoutingRuleRequest extends BaseApiRequest
{
    public const OPERATORS = [
        'eq', 'neq', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'gte',
        'lt', 'lte', 'in', 'not_in', 'is_null', 'not_null', 'is_empty', 'is_not_empty', 'before', 'after',
    ];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('lead_routing.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('lead_routing_rules', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('rule')),
            ],
            'strategy' => [$required, Rule::in(LeadRoutingService::STRATEGIES)],
            'match_type' => ['sometimes', Rule::in(['all', 'any'])],
            'priority' => ['sometimes', 'integer', 'between:0,65535'],
            'fallback_user_id' => ['nullable', 'integer', $this->memberRule()],
            'config' => ['nullable', 'array', 'max:30'],
            'config.candidate_strategy' => ['sometimes', Rule::in(['round_robin', 'load_balancing', 'availability', 'first'])],
            'active' => ['sometimes', 'boolean'],
            'stop_on_match' => ['sometimes', 'boolean'],
            'conditions' => [$required, 'array', 'max:50'],
            'conditions.*' => ['array', 'max:10'],
            'conditions.*.field' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z][A-Za-z0-9_.]*$/'],
            'conditions.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'conditions.*.value' => ['nullable'],
            'conditions.*.position' => ['sometimes', 'integer', 'between:0,65535'],
            'actions' => [$required, 'array', 'between:1,100'],
            'actions.*' => ['array', 'max:15'],
            'actions.*.user_id' => ['required', 'integer', 'distinct', $this->memberRule()],
            'actions.*.type' => ['sometimes', Rule::in(['candidate', 'assign', 'fallback'])],
            'actions.*.position' => ['sometimes', 'integer', 'between:0,65535'],
            'actions.*.weight' => ['sometimes', 'integer', 'between:1,100'],
            'actions.*.capacity' => ['nullable', 'integer', 'between:1,1000000'],
            'actions.*.config' => ['nullable', 'array', 'max:20'],
            'actions.*.config.minimum_score' => ['sometimes', 'integer', 'between:0,100'],
            'actions.*.config.maximum_score' => ['sometimes', 'integer', 'between:0,100'],
            'actions.*.config.available' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('conditions', []) as $index => $condition) {
                $field = is_array($condition) ? (string) ($condition['field'] ?? '') : '';
                if (! $this->allowedField($field)) {
                    $validator->errors()->add("conditions.$index.field", 'This lead field cannot be used for routing.');
                }
            }
            foreach ((array) $this->input('actions', []) as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }
                $minimum = data_get($action, 'config.minimum_score');
                $maximum = data_get($action, 'config.maximum_score');
                if ($minimum !== null && $maximum !== null && (int) $minimum > (int) $maximum) {
                    $validator->errors()->add("actions.$index.config.maximum_score", 'Maximum score must be at least minimum score.');
                }
            }
        }];
    }

    private function allowedField(string $field): bool
    {
        $field = str_starts_with($field, 'lead.') ? substr($field, 5) : $field;
        if (str_starts_with($field, 'custom_fields.')) {
            return preg_match('/^custom_fields\.[A-Za-z][A-Za-z0-9_]{0,119}$/', $field) === 1;
        }

        return in_array($field, [
            'first_name', 'last_name', 'email', 'phone', 'source', 'capture_origin', 'status',
            'score', 'score_classification', 'medium', 'campaign', 'content', 'term', 'utm_source',
            'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'landing_page', 'referrer',
            'owner_id', 'organization_id', 'contact_id', 'created_at',
        ], true);
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['strategy', 'match_type'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = mb_strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
