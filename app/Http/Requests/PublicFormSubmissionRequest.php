<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicFormSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'max:100'],
            'attribution' => ['nullable', 'array', 'max:20'],
            'attribution.source' => ['nullable', 'string', 'max:190'],
            'attribution.medium' => ['nullable', 'string', 'max:190'],
            'attribution.campaign' => ['nullable', 'string', 'max:190'],
            'attribution.content' => ['nullable', 'string', 'max:190'],
            'attribution.term' => ['nullable', 'string', 'max:190'],
            'attribution.utm_source' => ['nullable', 'string', 'max:190'],
            'attribution.utm_medium' => ['nullable', 'string', 'max:190'],
            'attribution.utm_campaign' => ['nullable', 'string', 'max:190'],
            'attribution.utm_content' => ['nullable', 'string', 'max:190'],
            'attribution.utm_term' => ['nullable', 'string', 'max:190'],
            'attribution.landing_page' => ['nullable', 'url:http,https', 'max:2000'],
            'attribution.referrer' => ['nullable', 'url:http,https', 'max:2000'],
            'captcha_token' => ['nullable', 'string', 'max:2048'],
            'form_session' => ['nullable', 'string', 'max:10000'],
            '_honeypot' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'between:8,190', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }
}
