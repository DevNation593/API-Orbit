<?php

namespace App\Services;

use App\Models\SequenceEnrollment;

final class SequenceTemplateRenderer
{
    public function render(string $template, SequenceEnrollment $enrollment): string
    {
        $enrollment->loadMissing(['lead.contact', 'contact', 'sender', 'sequence']);
        $lead = $enrollment->lead;
        $contact = $enrollment->contact ?? $lead?->contact;
        $values = [
            'lead.first_name' => $lead?->first_name,
            'lead.last_name' => $lead?->last_name,
            'lead.email' => $lead?->email,
            'lead.phone' => $lead?->phone,
            'contact.first_name' => $contact?->first_name,
            'contact.last_name' => $contact?->last_name,
            'contact.email' => $contact?->email,
            'contact.phone' => $contact?->phone,
            'sender.name' => $enrollment->sender?->name,
            'sender.email' => $enrollment->sender?->email,
            'sequence.name' => $enrollment->sequence?->name,
        ];

        return (string) preg_replace_callback('/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i', function (array $match) use ($values): string {
            return mb_substr((string) ($values[mb_strtolower($match[1])] ?? ''), 0, 5000);
        }, $template);
    }

    public function renderValue(mixed $value, SequenceEnrollment $enrollment): mixed
    {
        if (is_string($value)) {
            return $this->render($value, $enrollment);
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->renderValue($item, $enrollment), $value);
        }

        return $value;
    }
}
