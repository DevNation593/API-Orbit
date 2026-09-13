<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class KnowledgeArticleRequest extends KnowledgeRequest
{
    private const EDITORIAL_FIELDS = [
        'title',
        'summary',
        'body_html',
        'visibility',
        'category_id',
        'tag_ids',
        'seo_title',
        'seo_description',
        'change_summary',
    ];

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['title', 'body_html'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim((string) $this->input($field));
            }
        }
        if (is_string($this->input('visibility'))) {
            $normalized['visibility'] = strtoupper(trim((string) $this->input('visibility')));
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'body_html' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:1', 'max:200000'],
            'visibility' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(['PUBLIC', 'CUSTOMER', 'INTERNAL'])],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tag_ids' => ['sometimes', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'distinct', 'min:1'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:170'],
            'change_summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_version' => [$this->isMethod('patch') ? 'required' : 'prohibited', 'integer', 'min:1'],
            ...$this->serverControlledRules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isMethod('patch')) {
                    return;
                }

                $input = $this->all();
                if (! collect(self::EDITORIAL_FIELDS)->contains(
                    fn (string $field): bool => array_key_exists($field, $input)
                )) {
                    $validator->errors()->add(
                        'expected_version',
                        'Provide at least one editorial field to create a new version.',
                    );
                }
            },
        ];
    }
}
