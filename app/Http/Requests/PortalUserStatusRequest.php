<?php

namespace App\Http\Requests;

use App\Models\PortalUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PortalUserStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', PortalUser::class);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                PortalUser::STATUS_ACTIVE,
                PortalUser::STATUS_SUSPENDED,
            ])],
            'tenant_id' => ['missing'],
            'contact_id' => ['missing'],
            'email' => ['missing'],
            'password' => ['missing'],
        ];
    }
}
