<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ExportRequest extends BaseApiRequest
{
    /** @var array<string, array<int, string>> */
    private const COLUMNS = [
        'contacts' => ['id', 'first_name', 'last_name', 'email', 'phone', 'status'],
        'organizations' => ['id', 'name', 'email', 'phone', 'website'],
        'leads' => ['id', 'first_name', 'last_name', 'email', 'source', 'status', 'score'],
        'deals' => ['id', 'name', 'value', 'currency', 'status', 'expected_close_date'],
        'tasks' => ['id', 'title', 'status', 'priority', 'due_at'],
    ];

    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'in:contacts,organizations,leads,deals,tasks'],
            'filter' => ['nullable', 'array'],
            'columns' => ['nullable', 'array', 'max:100'],
            'columns.*' => ['string', 'max:80', Rule::in(self::COLUMNS[$this->input('entity_type')] ?? [])],
        ];
    }
}
