<?php

namespace App\Services;

use App\Events\LeadCaptured;
use App\Events\LeadCreated;
use App\Models\Lead;
use App\Models\LeadCaptureEvent;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final class LeadCaptureService
{
    public const ORIGINS = [
        'manual', 'form', 'landing', 'api', 'webhook', 'email', 'whatsapp', 'chat',
        'facebook', 'instagram', 'google_ads', 'meta_ads', 'import', 'qr',
    ];

    public const ATTRIBUTION_FIELDS = [
        'source', 'medium', 'campaign', 'content', 'term', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_content', 'utm_term', 'landing_page', 'referrer',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly DuplicateNormalizer $normalizer,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $leadData
     * @param  array<string, mixed>  $attribution
     * @return array{lead: Lead, capture: LeadCaptureEvent, created: bool, replayed: bool}
     */
    public function capture(
        array $leadData,
        string $origin,
        array $attribution = [],
        ?string $idempotencyKey = null,
        ?int $formSubmissionId = null,
        string $duplicateStrategy = 'update',
    ): array {
        $origin = mb_strtolower(trim($origin));
        if (! in_array($origin, self::ORIGINS, true)) {
            throw ValidationException::withMessages(['capture_origin' => 'The capture origin is not supported.']);
        }
        if (! in_array($duplicateStrategy, ['create', 'update', 'reject'], true)) {
            throw ValidationException::withMessages(['duplicate_strategy' => 'The duplicate strategy is not supported.']);
        }

        $attribution = $this->normaliseAttribution($attribution);
        $keyHash = filled($idempotencyKey) ? hash('sha256', (string) $idempotencyKey) : null;

        if ($keyHash !== null) {
            $existing = LeadCaptureEvent::with('lead')
                ->where('origin', $origin)->where('idempotency_key_hash', $keyHash)->first();
            if ($existing !== null) {
                return ['lead' => $existing->lead, 'capture' => $existing, 'created' => false, 'replayed' => true];
            }
        }

        return $this->database->transaction(function () use (
            $leadData, $origin, $attribution, $keyHash, $formSubmissionId, $duplicateStrategy,
        ): array {
            if ($keyHash !== null) {
                $existing = LeadCaptureEvent::with('lead')->lockForUpdate()
                    ->where('origin', $origin)->where('idempotency_key_hash', $keyHash)->first();
                if ($existing !== null) {
                    return ['lead' => $existing->lead, 'capture' => $existing, 'created' => false, 'replayed' => true];
                }
            }

            $email = $this->normalizer->email($leadData['email'] ?? null);
            $phone = $this->normalizer->phone($leadData['phone'] ?? null);
            $lead = $duplicateStrategy === 'create' ? null : $this->duplicate($email, $phone);
            if ($lead !== null && $duplicateStrategy === 'reject') {
                throw ValidationException::withMessages(['fields' => 'A lead with the same email or phone already exists.']);
            }

            $touch = ['origin' => $origin, 'occurred_at' => now()->toISOString(), ...$attribution];
            $attributes = $this->leadAttributes($leadData, $origin, $attribution, $touch);
            $created = $lead === null;
            if ($created) {
                $lead = Lead::create($attributes);
                $this->audit->record('create', $lead, newValues: $lead->getAttributes());
            } else {
                $old = $lead->getAttributes();
                $lead->update($this->mergeDuplicate($lead, $attributes));
                $this->audit->record('lead_recaptured', $lead, oldValues: $old, newValues: [
                    'origin' => $origin,
                    'last_touch' => $touch,
                ]);
            }

            $capture = LeadCaptureEvent::create([
                'lead_id' => $lead->id,
                'form_submission_id' => $formSubmissionId,
                'origin' => $origin,
                'idempotency_key_hash' => $keyHash,
                'attribution' => $attribution,
                'payload' => $this->capturePayload($leadData),
                'occurred_at' => now(),
            ]);
            $this->audit->record('lead_captured', $capture, newValues: [
                'lead_id' => (int) $lead->id,
                'origin' => $origin,
                'form_submission_id' => $formSubmissionId,
            ]);

            if ($created) {
                LeadCreated::dispatch($lead);
            }
            LeadCaptured::dispatch($lead, $capture);

            return ['lead' => $lead->fresh(), 'capture' => $capture, 'created' => $created, 'replayed' => false];
        });
    }

    /** @param array<string, mixed> $attribution
     * @return array<string, string>
     */
    public function normaliseAttribution(array $attribution): array
    {
        $normalised = [];
        foreach (self::ATTRIBUTION_FIELDS as $field) {
            if (! is_scalar($attribution[$field] ?? null)) {
                continue;
            }
            $value = trim((string) $attribution[$field]);
            if ($value === '') {
                continue;
            }
            $limit = in_array($field, ['landing_page', 'referrer'], true) ? 2000 : 190;
            $normalised[$field] = mb_substr($value, 0, $limit);
        }

        return $normalised;
    }

    private function duplicate(?string $email, ?string $phone): ?Lead
    {
        if ($email === null && $phone === null) {
            return null;
        }

        return Lead::query()->whereNull('converted_at')->where(function ($query) use ($email, $phone): void {
            if ($email !== null) {
                $query->where('email_normalized', $email);
            }
            if ($phone !== null) {
                $method = $email === null ? 'where' : 'orWhere';
                $query->{$method}('phone_normalized', $phone);
            }
        })->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $leadData
     * @param  array<string, string>  $attribution
     * @param  array<string, mixed>  $touch
     * @return array<string, mixed>
     */
    private function leadAttributes(array $leadData, string $origin, array $attribution, array $touch): array
    {
        $allowed = [
            'first_name', 'last_name', 'email', 'phone', 'source', 'owner_id', 'contact_id',
            'organization_id', 'status', 'score', 'custom_fields',
        ];
        $attributes = array_intersect_key($leadData, array_flip($allowed));
        $attributes['capture_origin'] = $origin;
        $attributes['source'] ??= $attribution['source'] ?? $origin;
        foreach (self::ATTRIBUTION_FIELDS as $field) {
            if ($field !== 'source' && array_key_exists($field, $attribution)) {
                $attributes[$field] = $attribution[$field];
            }
        }
        $attributes['first_touch'] = $touch;
        $attributes['last_touch'] = $touch;

        return $attributes;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function mergeDuplicate(Lead $lead, array $attributes): array
    {
        $updates = ['last_touch' => $attributes['last_touch']];
        foreach ([
            'first_name', 'last_name', 'email', 'phone', 'source', 'medium', 'campaign', 'content', 'term',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'landing_page', 'referrer',
        ] as $field) {
            if (blank($lead->getAttribute($field)) && filled($attributes[$field] ?? null)) {
                $updates[$field] = $attributes[$field];
            }
        }
        if (isset($attributes['custom_fields']) && is_array($attributes['custom_fields'])) {
            $updates['custom_fields'] = array_replace($lead->custom_fields ?? [], $attributes['custom_fields']);
        }

        return $updates;
    }

    /** @param array<string, mixed> $leadData
     * @return array<string, mixed>
     */
    private function capturePayload(array $leadData): array
    {
        return collect($leadData)->only(['first_name', 'last_name', 'email', 'phone', 'source', 'custom_fields'])->all();
    }
}
