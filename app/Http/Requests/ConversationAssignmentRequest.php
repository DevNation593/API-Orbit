<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class ConversationAssignmentRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'assigned_user_id' => ['present', 'nullable', 'integer', $this->memberRule()],
            'assigned_role_id' => ['present', 'nullable', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId()),
            )],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
