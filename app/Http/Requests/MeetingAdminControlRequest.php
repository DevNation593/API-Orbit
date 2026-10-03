<?php

namespace App\Http\Requests;

class MeetingAdminControlRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('meetings.manage') === true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
