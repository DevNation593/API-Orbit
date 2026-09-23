<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownRootKeys;
use Closure;
use Illuminate\Validation\Rules\Password;

class PortalInvitationAcceptRequest extends BaseApiRequest
{
    use RejectsUnknownRootKeys;

    public function rules(): array
    {
        return $this->withStrictRootKeys([
            'password' => ['required', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string'],
            'device_name' => [
                'nullable',
                'string',
                'min:1',
                'max:120',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $token = $this->route('token');
                    if (
                        is_string($value)
                        && is_string($token)
                        && $token !== ''
                        && str_contains($value, $token)
                    ) {
                        $fail('The device name is invalid.');
                    }
                },
            ],
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
