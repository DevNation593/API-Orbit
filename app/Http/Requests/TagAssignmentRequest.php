<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class TagAssignmentRequest extends BaseApiRequest
{
    public const TYPES = [
        'contact', 'contacts', 'organization', 'organizations', 'company', 'companies',
        'lead', 'leads', 'deal', 'deals', 'opportunity', 'opportunities',
        'task', 'tasks', 'activity', 'activities', 'file', 'files', 'document', 'documents',
        'entity_record',
        'conversation', 'conversations',
    ];

    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(self::TYPES)],
            'entity_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
