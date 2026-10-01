<?php

namespace App\Http\Requests;

class SequenceControlRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sequences.enroll') === true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:190']];
    }
}
