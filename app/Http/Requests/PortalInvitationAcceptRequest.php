<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownRootKeys;
use Illuminate\Validation\Rules\Password;

class PortalInvitationAcceptRequest extends BaseApiRequest
{
    use RejectsUnknownRootKeys;

    public function rules(): array
    {
        return $this->withStrictRootKeys([
            'password' => ['required', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'min:1', 'max:120'],
            'tenant_id' => ['missing'],
            'contact_id' => ['missing'],
            'email' => ['missing'],
            'token' => ['missing'],
            'token_hash' => ['missing'],
            'status' => ['missing'],
            'expires_at' => ['missing'],
            'accepted_at' => ['missing'],
            'invited_by' => ['missing'],
        ], ['password', 'password_confirmation', 'device_name']);
    }
}
