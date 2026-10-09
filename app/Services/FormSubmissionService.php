<?php

namespace App\Services;

use App\Events\ContactCreated;
use App\Jobs\RunAutomationJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\FileRecord;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class FormSubmissionService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ValidationFactory $validator,
        private readonly FormCaptchaVerifier $captcha,
        private readonly LeadCaptureService $capture,
        private readonly DuplicateNormalizer $normalizer,
        private readonly CustomFieldService $customFields,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function publicDefinition(Form $form): array
    {
        $settings = $form->settings ?? [];

        return [
            'public_id' => $form->public_id,
            'title' => $form->title,
            'description' => $form->description,
            'fields' => $form->fields->where('active', true)->values()->map(fn (FormField $field): array => [
                'key' => $field->field_key,
                'label' => $field->label,
                'type' => $field->type,
                'placeholder' => $field->placeholder,
                'help_text' => $field->help_text,
                'options' => $field->options,
                'default_value' => $field->default_value,
                'required' => $field->required,
                'settings' => $field->settings,
            ])->all(),
            'form_session' => $this->issueSession($form),
            'captcha' => (bool) data_get($settings, 'captcha_required', false) ? [
                'provider' => 'turnstile',
                'site_key' => config('services.turnstile.site_key'),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{submission: FormSubmission, replayed: bool, rejected: bool}
     */
    public function submit(Form $form, array $input, Request $request): array
    {
        $key = filled($input['idempotency_key'] ?? null) ? (string) $input['idempotency_key'] : null;
        $keyHash = $key === null ? null : hash('sha256', $key);
        if ($keyHash !== null) {
            $existing = FormSubmission::query()->where('form_id', $form->id)
                ->where('idempotency_key_hash', $keyHash)->first();
            if ($existing !== null) {
                return ['submission' => $existing, 'replayed' => true, 'rejected' => $existing->status === 'rejected'];
            }
        }

        if ((bool) data_get($form->settings, 'honeypot_enabled', true) && filled($input['_honeypot'] ?? null)) {
            $submission = $this->rejected($form, $input, $request, $keyHash);

            return ['submission' => $submission, 'replayed' => false, 'rejected' => true];
        }

        $this->validateSession($form, $input['form_session'] ?? null);
        $captchaVerified = $this->captcha->verify($form, $input['captcha_token'] ?? null, $request->ip());
        $values = $this->validateFields($form, (array) ($input['fields'] ?? []));
        $mapped = $this->mapFields($form, $values);
        $attribution = $this->capture->normaliseAttribution(array_replace(
            (array) ($input['attribution'] ?? []),
            $mapped['attribution'],
        ));
        $storedPaths = [];
        $automation = null;

        try {
            $submission = $this->database->transaction(function () use (
                $form, $request, $key, $keyHash, $captchaVerified, $values, $mapped, $attribution, &$storedPaths, &$automation,
            ): FormSubmission {
                if ($keyHash !== null) {
                    $existing = FormSubmission::query()->where('form_id', $form->id)
                        ->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $submission = FormSubmission::create([
                    'form_id' => $form->id,
                    'status' => 'received',
                    'payload' => $this->serialisableFields($values),
                    'attribution' => $attribution,
                    'idempotency_key_hash' => $keyHash,
                    'ip_hash' => $this->requestHash($request->ip()),
                    'user_agent_hash' => $this->requestHash($request->userAgent()),
                    'captcha_verified' => $captchaVerified,
                ]);

                $resolved = $this->storeFiles($form, $submission, $values, $storedPaths);
                $mapped = $this->mapFields($form, $resolved);
                $createLead = (bool) data_get($form->settings, 'create_lead', true);
                $createContact = (bool) data_get($form->settings, 'create_contact', false);
                $contact = $createContact ? $this->contact($mapped) : null;
                $lead = null;
                if ($createLead) {
                    $leadData = $mapped['lead'];
                    $leadData['custom_fields'] = $this->customFields->validateAndNormalise('leads', $mapped['lead_custom']);
                    if ($contact !== null) {
                        $leadData['contact_id'] = $contact->id;
                    }
                    $result = $this->capture->capture(
                        $leadData,
                        'form',
                        $attribution,
                        $key === null ? null : 'form:'.$form->id.':'.$key,
                        $submission->id,
                        (string) data_get($form->settings, 'duplicate_strategy', 'update'),
                    );
                    $lead = $result['lead'];
                }

                $related = $lead ?? $contact;
                if ($related !== null) {
                    FileRecord::query()->whereIn('id', $mapped['file_ids'])->update([
                        'related_type' => $related->getMorphClass(),
                        'related_id' => (string) $related->getKey(),
                    ]);
                }
                $submission->update([
                    'lead_id' => $lead?->id,
                    'contact_id' => $contact?->id,
                    'status' => 'processed',
                    'payload' => $this->serialisableFields($resolved),
                    'processed_at' => now(),
                ]);
                $form->increment('submissions_count');
                $this->audit->record('form_submitted', $submission, newValues: [
                    'form_id' => (int) $form->id,
                    'lead_id' => $lead?->id,
                    'contact_id' => $contact?->id,
                    'origin' => 'form',
                ]);

                $automationId = data_get($form->settings, 'automation_id');
                if ($automationId !== null && $related !== null) {
                    $automation = Automation::query()->whereKey($automationId)->where('active', true)->first();
                    if ($automation !== null) {
                        $automation->setRelation('submission_target', $related);
                    }
                }

                return $submission->fresh(['lead:id,first_name,last_name,email', 'contact:id,first_name,last_name,email']);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        if ($automation !== null && $automation->relationLoaded('submission_target')) {
            $target = $automation->getRelation('submission_target');
            RunAutomationJob::dispatch(
                $this->context->requireId(),
                (int) $automation->id,
                'form.submitted',
                'form-submission-'.$submission->id,
                $target::class,
                (int) $target->getKey(),
            )->onQueue('automations');
        }

        return ['submission' => $submission, 'replayed' => false, 'rejected' => false];
    }

    private function issueSession(Form $form): string
    {
        return Crypt::encryptString(json_encode([
            'form_id' => (int) $form->id,
            'issued_at' => now()->timestamp,
            'nonce' => Str::random(24),
        ], JSON_THROW_ON_ERROR));
    }

    private function validateSession(Form $form, mixed $session): void
    {
        if (! (bool) data_get($form->settings, 'require_form_session', true)) {
            return;
        }
        try {
            $payload = json_decode(Crypt::decryptString((string) $session), true, flags: JSON_THROW_ON_ERROR);
            $issuedAt = (int) ($payload['issued_at'] ?? 0);
            $age = now()->timestamp - $issuedAt;
            $minimum = (int) data_get($form->settings, 'minimum_fill_seconds', 2);
            $maximum = (int) data_get($form->settings, 'session_lifetime_minutes', 120) * 60;
            $valid = (int) ($payload['form_id'] ?? 0) === (int) $form->id && $age >= $minimum && $age <= $maximum;
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['form_session' => 'The form session is invalid or expired.']);
        }
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function validateFields(Form $form, array $fields): array
    {
        $definitions = $form->fields->where('active', true)->keyBy('field_key');
        $unknown = array_diff(array_keys($fields), $definitions->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => 'Unknown form fields: '.implode(', ', array_slice($unknown, 0, 10)).'.']);
        }

        $rules = [];
        foreach ($definitions as $key => $field) {
            if (! array_key_exists($key, $fields) && $field->default_value !== null) {
                $fields[$key] = $field->default_value;
            }
            $rules[$key] = $this->rulesFor($field);
        }

        return $this->validator->make($fields, $rules)->validate();
    }

    /** @return array<int, mixed> */
    private function rulesFor(FormField $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];
        $validation = $field->validation_rules ?? [];
        $options = collect($field->options ?? [])->map(function ($option) {
            return is_array($option) ? ($option['value'] ?? null) : $option;
        })->filter(fn ($option) => is_scalar($option))->map(fn ($option) => (string) $option)->values()->all();
        $rules = [...$rules, ...match ($field->type) {
            'text', 'custom_field' => ['string', 'max:'.(int) ($validation['max_length'] ?? 5000)],
            'textarea' => ['string', 'max:'.(int) ($validation['max_length'] ?? 50000)],
            'email' => ['email:rfc', 'max:190'],
            'phone' => ['string', 'max:50', 'regex:/^\+?[0-9() .-]{6,50}$/'],
            'number' => ['numeric'],
            'date' => ['date_format:Y-m-d'],
            'datetime' => ['date'],
            'select', 'radio' => ['string', Rule::in($options)],
            'multi_select' => ['array', 'max:100', Rule::forEach(fn () => ['string', Rule::in($options)])],
            'checkbox' => ['boolean'],
            'file' => ['file', 'max:'.(int) ($validation['max_kb'] ?? 10240), 'mimes:'.implode(',', $validation['mimes'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp'])],
            default => throw ValidationException::withMessages(["fields.{$field->field_key}" => 'This field type is not supported.']),
        }];
        if (isset($validation['min_length']) && in_array($field->type, ['text', 'textarea', 'custom_field'], true)) {
            $rules[] = 'min:'.(int) $validation['min_length'];
        }
        if (isset($validation['min']) && $field->type === 'number') {
            $rules[] = 'min:'.$validation['min'];
        }
        if (isset($validation['max']) && $field->type === 'number') {
            $rules[] = 'max:'.$validation['max'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $values
     * @return array{lead: array<string, mixed>, contact: array<string, mixed>, lead_custom: array<string, mixed>, contact_custom: array<string, mixed>, attribution: array<string, mixed>, file_ids: array<int, int>}
     */
    private function mapFields(Form $form, array $values): array
    {
        $mapped = ['lead' => [], 'contact' => [], 'lead_custom' => [], 'contact_custom' => [], 'attribution' => [], 'file_ids' => []];
        foreach ($form->fields->where('active', true) as $field) {
            if (! array_key_exists($field->field_key, $values)) {
                continue;
            }
            $value = $values[$field->field_key];
            if (is_int($value) && $field->type === 'file') {
                $mapped['file_ids'][] = $value;
            }
            $target = $field->mapping_target;
            if ($target === null && $field->field_definition_id !== null) {
                $definition = $field->fieldDefinition;
                $prefix = $definition?->entity_type === 'contacts' ? 'contact.custom_fields.' : 'lead.custom_fields.';
                $target = $prefix.$definition?->name;
            }
            if ($target === null) {
                continue;
            }
            if (str_starts_with($target, 'lead.custom_fields.')) {
                $mapped['lead_custom'][substr($target, 19)] = $value;
            } elseif (str_starts_with($target, 'contact.custom_fields.')) {
                $mapped['contact_custom'][substr($target, 22)] = $value;
            } elseif (str_starts_with($target, 'custom_fields.')) {
                $mapped['lead_custom'][substr($target, 14)] = $value;
            } elseif (str_starts_with($target, 'lead.')) {
                $mapped['lead'][substr($target, 5)] = $value;
            } elseif (str_starts_with($target, 'contact.')) {
                $mapped['contact'][substr($target, 8)] = $value;
            } elseif (str_starts_with($target, 'attribution.')) {
                $mapped['attribution'][substr($target, 12)] = $value;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, array{0: string, 1: string}>  $storedPaths
     * @return array<string, mixed>
     */
    private function storeFiles(Form $form, FormSubmission $submission, array $values, array &$storedPaths): array
    {
        $disk = (string) config('filesystems.default');
        foreach ($form->fields->where('active', true)->where('type', 'file') as $field) {
            $file = $values[$field->field_key] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $extension = mb_strtolower($file->getClientOriginalExtension() ?: 'bin');
            $path = 'tenants/'.$this->context->requireId().'/forms/'.$submission->public_id.'/'.Str::uuid().'.'.$extension;
            $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
            if ($stored === false) {
                throw new RuntimeException('The submitted file could not be stored.');
            }
            $storedPaths[] = [$disk, $path];
            $record = FileRecord::create([
                'disk' => $disk,
                'path' => $path,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'metadata' => ['form_submission_id' => $submission->public_id, 'field_key' => $field->field_key],
            ]);
            $values[$field->field_key] = (int) $record->id;
        }

        return $values;
    }

    /** @param array<string, mixed> $mapped */
    private function contact(array $mapped): Contact
    {
        $data = $mapped['contact'];
        foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
            $data[$field] ??= $mapped['lead'][$field] ?? null;
        }
        $custom = $this->customFields->validateAndNormalise('contacts', $mapped['contact_custom']);
        $email = $this->normalizer->email($data['email'] ?? null);
        $phone = $this->normalizer->phone($data['phone'] ?? null);
        $contact = null;
        if ($email !== null || $phone !== null) {
            $contact = Contact::query()->where(function ($query) use ($email, $phone): void {
                if ($email !== null) {
                    $query->where('email_normalized', $email);
                }
                if ($phone !== null) {
                    $query->{$email === null ? 'where' : 'orWhere'}('phone_normalized', $phone);
                }
            })->lockForUpdate()->first();
        }
        if ($contact !== null) {
            $updates = [];
            foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
                if (blank($contact->getAttribute($field)) && filled($data[$field] ?? null)) {
                    $updates[$field] = $data[$field];
                }
            }
            if ($custom !== []) {
                $updates['custom_fields'] = array_replace($contact->custom_fields ?? [], $custom);
            }
            if ($updates !== []) {
                $old = $contact->getAttributes();
                $contact->update($updates);
                $this->audit->record('update', $contact, oldValues: $old, newValues: $contact->getAttributes());
            }

            return $contact;
        }

        $contact = Contact::create([
            'first_name' => filled($data['first_name'] ?? null) ? $data['first_name'] : 'Website visitor',
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
            'custom_fields' => $custom,
        ]);
        $this->audit->record('create', $contact, newValues: $contact->getAttributes());
        ContactCreated::dispatch($contact);

        return $contact;
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function serialisableFields(array $fields): array
    {
        return collect($fields)->map(fn ($value) => $value instanceof UploadedFile ? [
            'filename' => $value->getClientOriginalName(),
            'mime_type' => $value->getMimeType(),
            'size' => $value->getSize(),
        ] : $value)->all();
    }

    /** @param array<string, mixed> $input */
    private function rejected(Form $form, array $input, Request $request, ?string $keyHash): FormSubmission
    {
        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'status' => 'rejected',
            'payload' => [],
            'attribution' => $this->capture->normaliseAttribution((array) ($input['attribution'] ?? [])),
            'idempotency_key_hash' => $keyHash,
            'ip_hash' => $this->requestHash($request->ip()),
            'user_agent_hash' => $this->requestHash($request->userAgent()),
            'spam_score' => 100,
            'captcha_verified' => false,
            'processed_at' => now(),
        ]);
        $form->increment('submissions_count');

        return $submission;
    }

    private function requestHash(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return hash_hmac('sha256', (string) $value, (string) config('app.key'));
    }
}
