<?php

namespace App\Http\Requests;

use App\Services\SegmentEngine;
use Illuminate\Validation\Rule;

class SegmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->routeIs('marketing.segments.preview') ? 'segments.view' : 'segments.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('patch') ? 'sometimes' : 'required';

        return [
            'name' => [$this->routeIs('marketing.segments.preview') ? 'sometimes' : $required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'entity_type' => [$required, Rule::in(SegmentEngine::TYPES)],
            'entity_definition_id' => ['nullable', 'integer', 'min:1'],
            'definition' => [$required, 'array'],
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
