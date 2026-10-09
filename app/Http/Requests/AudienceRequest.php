<?php

namespace App\Http\Requests;

use App\Services\SegmentEngine;
use Illuminate\Validation\Rule;

class AudienceRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('audiences.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('patch') ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'entity_type' => [$required, Rule::in(['contacts', 'leads'])],
            'type' => [$required, Rule::in(['static', 'dynamic'])],
            'segment_id' => ['nullable', 'integer', 'min:1'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('entity_type'))) {
            $this->merge(['entity_type' => app(SegmentEngine::class)->normalize($this->input('entity_type'))]);
        }
    }
}
