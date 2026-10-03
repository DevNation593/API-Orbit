<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ApprovalDecisionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('approvals.decide') === true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comment' => ['nullable', 'string', 'max:10000', 'required_if:decision,reject'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('decision'))) {
            $this->merge(['decision' => strtolower(trim($this->input('decision')))]);
        }
    }
}
