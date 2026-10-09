<?php

namespace App\Http\Requests;

use App\Services\LeadCaptureService;
use App\Support\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FormDefinitionRequest extends BaseApiRequest
{
    public const FIELD_TYPES = [
        'text', 'textarea', 'email', 'phone', 'number', 'date', 'datetime',
        'select', 'multi_select', 'checkbox', 'radio', 'file', 'custom_field',
    ];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('forms.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('forms', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('form')),
            ],
            'title' => [$required, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'success_message' => ['nullable', 'string', 'max:2000'],
            'redirect_url' => ['nullable', 'url:http,https', 'max:2000'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date', 'after:active_from'],
            'settings' => ['nullable', 'array', 'max:50'],
            'settings.create_lead' => ['sometimes', 'boolean'],
            'settings.create_contact' => ['sometimes', 'boolean'],
            'settings.duplicate_strategy' => ['sometimes', Rule::in(['create', 'update', 'reject'])],
            'settings.automation_id' => ['nullable', 'integer', Rule::exists('automations', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            )],
            'settings.captcha_required' => ['sometimes', 'boolean'],
            'settings.require_form_session' => ['sometimes', 'boolean'],
            'settings.minimum_fill_seconds' => ['sometimes', 'integer', 'between:0,300'],
            'settings.session_lifetime_minutes' => ['sometimes', 'integer', 'between:1,1440'],
            'settings.honeypot_enabled' => ['sometimes', 'boolean'],
            'fields' => [$required, 'array', 'between:1,100'],
            'fields.*' => ['required', 'array', 'max:30'],
            'fields.*.field_key' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:190'],
            'fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'fields.*.mapping_target' => ['nullable', 'string', 'max:190', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'fields.*.field_definition_id' => ['nullable', 'integer', Rule::exists('field_definitions', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true),
            )],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string', 'max:2000'],
            'fields.*.options' => ['nullable', 'array', 'max:100'],
            'fields.*.validation_rules' => ['nullable', 'array', 'max:20'],
            'fields.*.validation_rules.min_length' => ['sometimes', 'integer', 'between:0,100000'],
            'fields.*.validation_rules.max_length' => ['sometimes', 'integer', 'between:1,100000'],
            'fields.*.validation_rules.min' => ['sometimes', 'numeric'],
            'fields.*.validation_rules.max' => ['sometimes', 'numeric'],
            'fields.*.validation_rules.max_kb' => ['sometimes', 'integer', 'between:1,51200'],
            'fields.*.validation_rules.mimes' => ['sometimes', 'array', 'max:20'],
            'fields.*.validation_rules.mimes.*' => ['string', Rule::in(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp'])],
            'fields.*.default_value' => ['nullable'],
            'fields.*.settings' => ['nullable', 'array', 'max:20'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.position' => ['sometimes', 'integer', 'between:0,65535'],
            'fields.*.active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $settings = (array) $this->input('settings', []);
            if (array_key_exists('create_lead', $settings) && array_key_exists('create_contact', $settings)
                && ! filter_var($settings['create_lead'], FILTER_VALIDATE_BOOL)
                && ! filter_var($settings['create_contact'], FILTER_VALIDATE_BOOL)) {
                $validator->errors()->add('settings.create_lead', 'At least one CRM record creation option must be enabled.');
            }

            foreach ((array) $this->input('fields', []) as $index => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $target = $field['mapping_target'] ?? null;
                if (is_string($target) && ! $this->validMappingTarget($target)) {
                    $validator->errors()->add("fields.$index.mapping_target", 'The mapping target is not allowed.');
                }
                if (($field['type'] ?? null) === 'custom_field' && empty($field['field_definition_id']) && ! str_starts_with((string) $target, 'custom_fields.')) {
                    $validator->errors()->add("fields.$index.field_definition_id", 'A custom field definition or custom_fields mapping is required.');
                }
                if (in_array($field['type'] ?? null, ['select', 'multi_select', 'radio'], true) && empty($field['options'])) {
                    $validator->errors()->add("fields.$index.options", 'Selectable fields require at least one option.');
                }
                $minimum = Arr::get($field, 'validation_rules.min_length');
                $maximum = Arr::get($field, 'validation_rules.max_length');
                if ($minimum !== null && $maximum !== null && (int) $minimum > (int) $maximum) {
                    $validator->errors()->add("fields.$index.validation_rules.max_length", 'The maximum length must be at least the minimum length.');
                }
            }
        }];
    }

    private function validMappingTarget(string $target): bool
    {
        $direct = [
            'lead.first_name', 'lead.last_name', 'lead.email', 'lead.phone', 'lead.source',
            'contact.first_name', 'contact.last_name', 'contact.email', 'contact.phone',
        ];
        if (in_array($target, $direct, true)) {
            return true;
        }
        if (str_starts_with($target, 'custom_fields.')) {
            return preg_match('/^custom_fields\.[a-z][a-z0-9_]{0,119}$/', $target) === 1;
        }
        if (str_starts_with($target, 'lead.custom_fields.')) {
            return preg_match('/^lead\.custom_fields\.[a-z][a-z0-9_]{0,119}$/', $target) === 1;
        }
        if (str_starts_with($target, 'contact.custom_fields.')) {
            return preg_match('/^contact\.custom_fields\.[a-z][a-z0-9_]{0,119}$/', $target) === 1;
        }
        if (str_starts_with($target, 'attribution.')) {
            return in_array(substr($target, 12), LeadCaptureService::ATTRIBUTION_FIELDS, true);
        }

        return false;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['status'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = mb_strtolower(trim($this->input($field)));
            }
        }
        $fields = $this->input('fields');
        if (is_array($fields)) {
            foreach ($fields as &$field) {
                if (! is_array($field)) {
                    continue;
                }
                foreach (['field_key', 'type', 'mapping_target'] as $key) {
                    if (is_string($field[$key] ?? null)) {
                        $field[$key] = mb_strtolower(trim($field[$key]));
                    }
                }
            }
            unset($field);
            $data['fields'] = $fields;
        }
        $this->merge($data);
    }
}
