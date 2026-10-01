<?php

namespace App\Http\Requests;

class PlaybookAnswerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('playbooks.execute') === true;
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'min:1'],
            'value' => ['present'],
        ];
    }
}
