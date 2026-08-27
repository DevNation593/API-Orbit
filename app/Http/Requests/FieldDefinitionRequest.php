<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class FieldDefinitionRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $targetRule = $this->isMethod('get') ? 'sometimes' : 'required_without:entity_definition_id';
        $definitionRule = $this->isMethod('get') ? 'sometimes' : 'required_without:entity_type';

        return [
            'entity_type' => [$targetRule, 'nullable', 'string', Rule::in(['contacts', 'organizations', 'leads', 'deals', 'tasks', 'activities'])],
            'entity_definition_id' => [$definitionRule, 'nullable', 'integer', 'min:1'],
            'name' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'regex:/^[a-z][a-z0-9_]{1,79}$/'],
            'label' => [$this->isMethod('get') ? 'sometimes' : 'required', 'string', 'max:160'],
            'type' => [$this->isMethod('get') ? 'sometimes' : 'required', Rule::in(config('tenancy.allowed_custom_field_types'))],
            'required' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array', 'max:200'],
            'options.*' => ['string', 'max:190'],
            'validation_rules' => ['nullable', 'array'],
            'validation_rules.min' => ['nullable', 'numeric'],
            'validation_rules.max' => ['nullable', 'numeric'],
            'validation_rules.regex' => ['nullable', 'string', 'max:255'],
            'default_value' => ['nullable'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
