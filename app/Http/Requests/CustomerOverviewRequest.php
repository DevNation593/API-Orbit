<?php

namespace App\Http\Requests;

class CustomerOverviewRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return ['recent_limit' => ['sometimes', 'integer', 'between:1,10']];
    }
}
