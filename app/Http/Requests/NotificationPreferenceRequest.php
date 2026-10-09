<?php

namespace App\Http\Requests;

use App\Support\InboxCatalog;
use Illuminate\Validation\Rule;

class NotificationPreferenceRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'max:100'],
            'preferences.*.event' => ['required', 'string', 'max:120', 'regex:/^(\*|[a-z][a-z0-9_.-]+)$/'],
            'preferences.*.channel' => ['required', Rule::in(InboxCatalog::NOTIFICATION_CHANNELS)],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.delivery' => ['sometimes', Rule::in(['immediate', 'daily', 'weekly'])],
        ];
    }
}
