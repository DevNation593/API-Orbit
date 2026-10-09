<?php

namespace App\Http\Requests;

use App\Models\GoalTarget;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GoalTargetRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('goals.manage') === true;
    }

    public function rules(): array
    {
        return [
            'target_type' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(['tenant', 'user', 'team', 'branch', 'territory', 'product', 'industry'])],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'target_key' => ['nullable', 'string', 'max:190'],
            'target_value' => [$this->isMethod('post') ? 'required' : 'sometimes', 'decimal:0,6', 'min:0'],
            'weight' => ['sometimes', 'decimal:0,6', 'gt:0'],
            'settings' => ['nullable', 'array', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $target = $this->isMethod('post') ? null : GoalTarget::query()->find($this->route('target'));
            $type = $this->effectiveInput('target_type', $target?->target_type);
            $targetId = $this->effectiveInput('target_id', $target?->target_id);
            $targetKey = $this->effectiveInput('target_key', $target?->target_key);
            if ($type === 'industry' && blank($targetKey)) {
                $validator->errors()->add('target_key', 'Industry targets require target_key.');
            } elseif ($type === 'tenant' && filled($targetId)) {
                $validator->errors()->add('target_id', 'Tenant targets do not use target_id.');
            } elseif (! in_array($type, ['tenant', 'industry'], true) && blank($targetId)) {
                $validator->errors()->add('target_id', 'This target type requires target_id.');
            }
        }];
    }
}
