<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class InviteUserRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:190'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId()),
                ),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
