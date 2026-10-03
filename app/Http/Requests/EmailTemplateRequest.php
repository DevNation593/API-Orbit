<?php

namespace App\Http\Requests;

class EmailTemplateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'subject' => [$required, 'string', 'max:255'],
            'body_html' => [$this->isMethod('post') ? 'required_without:body_text' : 'sometimes', 'nullable', 'string', 'max:200000'],
            'body_text' => [$this->isMethod('post') ? 'required_without:body_html' : 'sometimes', 'nullable', 'string', 'max:200000'],
            'variables' => ['nullable', 'array', 'max:100'],
            'variables.*' => ['string', 'max:80', 'distinct', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
