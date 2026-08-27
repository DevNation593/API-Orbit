<?php

namespace App\Http\Requests;

class DealRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'pipeline_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:pipelines,id'],
            'stage_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:pipeline_stages,id'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:190'],
            'value' => [$this->isMethod('post') ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'currency' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'size:3', 'alpha'],
            'status' => ['sometimes', 'string', 'max:40'],
            'expected_close_date' => ['nullable', 'date'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
