<?php

namespace App\Services;

use App\Models\Form;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final class FormBuilderService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): Form
    {
        return $this->database->transaction(function () use ($data, $userId): Form {
            $fields = $data['fields'];
            unset($data['fields']);
            $data['created_by'] = $userId;
            $data['settings'] = $this->settings([], $data['settings'] ?? []);
            if (($data['status'] ?? 'draft') === 'published') {
                $data['published_at'] = now();
            }
            $form = Form::create($data);
            $this->syncFields($form, $fields);
            $this->audit->record('create', $form, newValues: $this->auditValues($form));

            return $form->load(['fields', 'creator:id,name']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Form $form, array $data): Form
    {
        return $this->database->transaction(function () use ($form, $data): Form {
            $fields = $data['fields'] ?? null;
            unset($data['fields']);
            if (array_key_exists('settings', $data)) {
                $data['settings'] = $this->settings($form->settings ?? [], $data['settings'] ?? []);
            }
            if (($data['status'] ?? null) === 'published' && $form->status !== 'published') {
                $data['published_at'] = now();
            }
            $old = $this->auditValues($form);
            $form->update($data);
            if (is_array($fields)) {
                $this->syncFields($form, $fields);
            }
            if ($form->status === 'published' && ! $form->fields()->where('active', true)->exists()) {
                throw ValidationException::withMessages(['fields' => 'A published form requires at least one active field.']);
            }
            $this->audit->record('update', $form, oldValues: $old, newValues: $this->auditValues($form));

            return $form->fresh()->load(['fields', 'creator:id,name']);
        });
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function syncFields(Form $form, array $fields): void
    {
        $keys = [];
        foreach (array_values($fields) as $position => $field) {
            $key = $field['field_key'];
            $keys[] = $key;
            $field['position'] = $field['position'] ?? $position;
            $form->fields()->updateOrCreate(['field_key' => $key], $field);
        }
        $form->fields()->whereNotIn('field_key', $keys)->delete();
    }

    /** @param array<string, mixed> $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function settings(array $current, array $incoming): array
    {
        $settings = array_replace([
            'create_lead' => true,
            'create_contact' => false,
            'duplicate_strategy' => 'update',
            'captcha_required' => false,
            'require_form_session' => true,
            'minimum_fill_seconds' => 2,
            'session_lifetime_minutes' => 120,
            'honeypot_enabled' => true,
        ], $current, $incoming);
        if (! (bool) $settings['create_lead'] && ! (bool) $settings['create_contact']) {
            throw ValidationException::withMessages(['settings.create_lead' => 'At least one CRM record creation option must be enabled.']);
        }

        return $settings;
    }

    /** @return array<string, mixed> */
    private function auditValues(Form $form): array
    {
        return [
            'name' => $form->name,
            'status' => $form->status,
            'public_id' => $form->public_id,
            'published_at' => $form->published_at?->toISOString(),
        ];
    }
}
