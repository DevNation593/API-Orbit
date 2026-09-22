<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownRootKeys;
use App\Models\PortalInvitation;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PortalInvitationRequest extends BaseApiRequest
{
    use RejectsUnknownRootKeys;

    public function authorize(): bool
    {
        return Gate::allows('create', PortalInvitation::class);
    }

    public function rules(): array
    {
        return $this->withStrictRootKeys([
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->whereNull('deleted_at'),
            ],
            'tenant_id' => ['missing'],
            'email' => ['missing'],
            'token' => ['missing'],
            'token_hash' => ['missing'],
            'status' => ['missing'],
            'expires_at' => ['missing'],
            'accepted_at' => ['missing'],
            'invited_by' => ['missing'],
        ], ['contact_id']);
    }
}
