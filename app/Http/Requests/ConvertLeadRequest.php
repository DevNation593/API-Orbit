<?php

namespace App\Http\Requests;

class ConvertLeadRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'create_contact' => ['sometimes', 'boolean'],
            'create_organization' => ['sometimes', 'boolean'],
            'deal' => ['nullable', 'array'],
            'deal.name' => ['required_with:deal', 'string', 'max:190'],
            'deal.pipeline_id' => ['required_with:deal', 'integer', 'exists:pipelines,id'],
            'deal.stage_id' => ['required_with:deal', 'integer', 'exists:pipeline_stages,id'],
            'deal.value' => ['required_with:deal', 'numeric', 'min:0'],
            'deal.currency' => ['required_with:deal', 'string', 'size:3', 'alpha'],
            'deal.expected_close_date' => ['nullable', 'date'],
        ];
    }
}
