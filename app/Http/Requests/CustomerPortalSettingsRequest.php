<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownRootKeys;
use App\Models\CustomerPortal;
use Illuminate\Support\Facades\Gate;

class CustomerPortalSettingsRequest extends BaseApiRequest
{
    use RejectsUnknownRootKeys;

    public function authorize(): bool
    {
        return Gate::allows('create', CustomerPortal::class);
    }

    public function rules(): array
    {
        return $this->withStrictRootKeys([
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'settings' => ['sometimes', 'array:welcome_message,support_email'],
            'settings.welcome_message' => ['nullable', 'string', 'max:500'],
            'settings.support_email' => ['nullable', 'email:rfc', 'max:190'],
            'id' => ['missing'],
            'tenant_id' => ['missing'],
            'public_id' => ['missing'],
            'slug' => ['missing'],
        ], ['title', 'is_active', 'settings']);
    }
}
