<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PlaybookRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('playbooks.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [$required, 'string', 'max:190', Rule::unique('playbooks', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('version', $this->input('version', 1))->whereNull('deleted_at'))->ignore($this->route('playbook'))],
            'entity_type' => [$required, Rule::in(['contact', 'organization', 'lead', 'deal'])],
            'version' => ['sometimes', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:20000'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:100'],
            'sections' => [$required, 'array', 'between:1,100'],
            'sections.*.title' => ['required', 'string', 'max:190'],
            'sections.*.description' => ['nullable', 'string', 'max:10000'],
            'sections.*.position' => ['required', 'integer', 'min:1', 'distinct'],
            'sections.*.required' => ['sometimes', 'boolean'],
            'sections.*.conditions' => ['nullable', 'array', 'max:50'],
            'sections.*.questions' => ['required', 'array', 'between:1,200'],
            'sections.*.questions.*.key' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,99}$/'],
            'sections.*.questions.*.prompt' => ['required', 'string', 'max:500'],
            'sections.*.questions.*.help_text' => ['nullable', 'string', 'max:5000'],
            'sections.*.questions.*.type' => ['required', Rule::in(['text', 'textarea', 'number', 'select', 'multiselect', 'boolean', 'date'])],
            'sections.*.questions.*.position' => ['required', 'integer', 'min:1'],
            'sections.*.questions.*.required' => ['sometimes', 'boolean'],
            'sections.*.questions.*.options' => ['nullable', 'array', 'max:200'],
            'sections.*.questions.*.validation' => ['nullable', 'array', 'max:30'],
            'sections.*.questions.*.score_config' => ['nullable', 'array', 'max:200'],
            'sections.*.questions.*.actions' => ['nullable', 'array', 'max:20'],
            'sections.*.questions.*.actions.*.type' => ['required', Rule::in(['update_field', 'calculate_score', 'trigger_workflow', 'create_task'])],
            'sections.*.questions.*.actions.*.field' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_.]{0,189}$/'],
            'sections.*.questions.*.actions.*.value' => ['nullable'],
            'sections.*.questions.*.actions.*.automation_id' => ['nullable', 'integer', Rule::exists('automations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'sections.*.questions.*.actions.*.title' => ['nullable', 'string', 'max:190'],
            'sections.*.questions.*.actions.*.assigned_to' => ['nullable', 'integer', $this->memberRule()],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = [];
            foreach ((array) $this->input('sections', []) as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }
                $positions = [];
                foreach ((array) ($section['questions'] ?? []) as $questionIndex => $question) {
                    if (! is_array($question)) {
                        continue;
                    }
                    $position = $question['position'] ?? null;
                    if ($position !== null && isset($positions[$position])) {
                        $validator->errors()->add("sections.$sectionIndex.questions.$questionIndex.position", 'Question positions must be unique within a section.');
                    }
                    $positions[$position] = true;
                    $key = mb_strtolower((string) ($question['key'] ?? ''));
                    if ($key !== '' && isset($keys[$key])) {
                        $validator->errors()->add("sections.$sectionIndex.questions.$questionIndex.key", 'Question keys must be unique within a playbook.');
                    }
                    $keys[$key] = true;
                    if (in_array($question['type'] ?? null, ['select', 'multiselect'], true) && blank($question['options'] ?? null)) {
                        $validator->errors()->add("sections.$sectionIndex.questions.$questionIndex.options", 'Select questions require at least one option.');
                    }
                    foreach ((array) ($question['actions'] ?? []) as $actionIndex => $action) {
                        if (! is_array($action)) {
                            continue;
                        }
                        $path = "sections.$sectionIndex.questions.$questionIndex.actions.$actionIndex";
                        if (($action['type'] ?? null) === 'update_field' && blank($action['field'] ?? null)) {
                            $validator->errors()->add("$path.field", 'Update-field actions require a field.');
                        }
                        if (($action['type'] ?? null) === 'trigger_workflow' && blank($action['automation_id'] ?? null)) {
                            $validator->errors()->add("$path.automation_id", 'Trigger-workflow actions require an automation.');
                        }
                        if (($action['type'] ?? null) === 'create_task' && blank($action['title'] ?? null)) {
                            $validator->errors()->add("$path.title", 'Create-task actions require a title.');
                        }
                    }
                }
            }
        }];
    }
}
