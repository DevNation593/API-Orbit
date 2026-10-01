<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StructureMemberRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('user') !== null) {
            $this->merge(['user_id' => (int) $this->route('user')]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales_structure.manage') === true || $this->user()?->hasPermission('territories.manage') === true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', $this->memberRule()],
            'role' => ['sometimes', Rule::in(['member', 'manager'])],
            'quota_weight' => ['sometimes', 'decimal:0,6', 'gt:0'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
