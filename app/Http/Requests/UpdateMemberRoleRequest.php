<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId()),
                ),
            ],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ];
    }
}
