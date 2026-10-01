<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class GlobalSearchRequest extends BaseApiRequest
{
    public const TYPES = [
        'contacts', 'companies', 'organizations', 'leads', 'opportunities', 'deals',
        'documents', 'files', 'custom_objects',
    ];

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'types' => ['sometimes', 'array', 'min:1', 'max:9'],
            'types.*' => ['string', Rule::in(self::TYPES), 'distinct'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }
}
