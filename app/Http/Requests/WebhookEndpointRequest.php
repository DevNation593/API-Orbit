<?php

namespace App\Http\Requests;

use App\Rules\SafeExternalUrl;

class WebhookEndpointRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'url' => ['required', 'url:http,https', 'max:2000', new SafeExternalUrl],
            'secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'events' => ['nullable', 'array', 'max:100'],
            'events.*' => ['string', 'regex:/^[a-z][a-z0-9_.-]{2,79}$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
