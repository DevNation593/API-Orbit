<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use Illuminate\Support\Str;

final class DuplicateNormalizer
{
    public function prepareContact(Contact $contact): void
    {
        $custom = is_array($contact->custom_fields) ? $contact->custom_fields : [];
        $contact->setAttribute('email_normalized', $this->email($contact->email));
        $contact->setAttribute('phone_normalized', $this->phone($contact->phone));
        $contact->setAttribute('name_normalized', $this->text(trim($contact->first_name.' '.$contact->last_name)));
        $contact->setAttribute('identification_normalized', $this->identifier(
            $custom['identification'] ?? $custom['document_number'] ?? null,
        ));
        $contact->setAttribute('tax_id_normalized', $this->identifier(
            $custom['tax_id'] ?? $custom['vat_number'] ?? $custom['ruc'] ?? null,
        ));
    }

    public function prepareOrganization(Organization $organization): void
    {
        $custom = is_array($organization->custom_fields) ? $organization->custom_fields : [];
        $organization->setAttribute('email_normalized', $this->email($organization->email));
        $organization->setAttribute('phone_normalized', $this->phone($organization->phone));
        $organization->setAttribute('name_normalized', $this->text($organization->name));
        $organization->setAttribute('website_normalized', $this->website($organization->website));
        $organization->setAttribute('identification_normalized', $this->identifier(
            $custom['identification'] ?? $custom['document_number'] ?? null,
        ));
        $organization->setAttribute('tax_id_normalized', $this->identifier(
            $custom['tax_id'] ?? $custom['vat_number'] ?? $custom['ruc'] ?? null,
        ));
    }

    public function prepareLead(Lead $lead): void
    {
        $lead->setAttribute('email_normalized', $this->email($lead->email));
        $lead->setAttribute('phone_normalized', $this->phone($lead->phone));
    }

    public function email(mixed $value): ?string
    {
        return $this->nullable(Str::lower(trim((string) $value)));
    }

    public function phone(mixed $value): ?string
    {
        return $this->nullable(preg_replace('/\D+/', '', (string) $value));
    }

    public function identifier(mixed $value): ?string
    {
        $value = preg_replace('/[^\pL\pN]+/u', '', trim((string) $value));

        return $this->nullable(Str::lower((string) $value));
    }

    public function text(mixed $value): ?string
    {
        $value = Str::lower(Str::ascii(trim((string) $value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return $this->nullable(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    public function website(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $host = parse_url($raw, PHP_URL_HOST) ?: parse_url('https://'.$raw, PHP_URL_HOST);
        $host = Str::lower((string) $host);

        return $this->nullable(preg_replace('/^www\./', '', rtrim($host, '.')));
    }

    private function nullable(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
