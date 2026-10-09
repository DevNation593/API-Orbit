<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApprovalProcessRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('approvals.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [$required, 'string', 'max:160'],
            'approvable_type' => [$required, Rule::in(['quote', 'discount', 'deal', 'contract'])],
            'version' => ['sometimes', 'integer', 'min:1'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'conditions' => ['nullable', 'array', 'max:100'],
            'match_type' => ['sometimes', Rule::in(['all', 'any'])],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:50'],
            'steps' => [$required, 'array', 'between:1,50'],
            'steps.*.name' => ['required', 'string', 'max:160'],
            'steps.*.position' => ['required', 'integer', 'min:1', 'distinct'],
            'steps.*.approver_type' => ['required', Rule::in(['user', 'role', 'permission'])],
            'steps.*.approver_user_id' => ['nullable', 'integer', $this->memberRule()],
            'steps.*.approver_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'steps.*.approver_permission' => ['nullable', 'string', Rule::exists('permissions', 'key')],
            'steps.*.minimum_approvals' => ['sometimes', 'integer', 'between:1,100'],
            'steps.*.decision_mode' => ['sometimes', Rule::in(['any', 'all'])],
            'steps.*.due_hours' => ['nullable', 'integer', 'between:1,8760'],
            'steps.*.escalation_user_id' => ['nullable', 'integer', $this->memberRule()],
            'steps.*.escalation_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'steps.*.conditions' => ['nullable', 'array', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('steps', []) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }
                $field = match ($step['approver_type'] ?? null) {
                    'user' => 'approver_user_id', 'role' => 'approver_role_id', 'permission' => 'approver_permission', default => null,
                };
                if ($field !== null && blank($step[$field] ?? null)) {
                    $validator->errors()->add("steps.$index.$field", 'This approver target is required for the selected type.');
                }
                if (blank($step['escalation_user_id'] ?? null) && blank($step['escalation_role_id'] ?? null) && filled($step['due_hours'] ?? null)) {
                    $validator->errors()->add("steps.$index.due_hours", 'A due time requires an escalation user or role.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['approvable_type', 'match_type'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower(trim($this->input($field)));
            }
        }
        if (is_array($this->input('steps'))) {
            $data['steps'] = array_map(function ($step) {
                if (! is_array($step)) {
                    return $step;
                }
                foreach (['approver_type', 'decision_mode'] as $field) {
                    if (is_string($step[$field] ?? null)) {
                        $step[$field] = strtolower(trim($step[$field]));
                    }
                }

                return $step;
            }, $this->input('steps'));
        }
        $this->merge($data);
    }
}
