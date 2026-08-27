<?php

namespace App\Http\Requests;

class EntityRecordRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return ['data' => [$this->isMethod('get') ? 'sometimes' : 'required', 'array', 'max:200']];
    }
}
