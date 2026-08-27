<?php

return [
    'header' => env('TENANT_HEADER', 'X-Tenant-ID'),
    'rls_enabled' => (bool) env('TENANT_RLS_ENABLED', false),
    'default_industry' => env('DEFAULT_INDUSTRY', 'general'),
    'allowed_custom_field_types' => [
        'text', 'textarea', 'number', 'decimal', 'currency', 'email', 'phone', 'url',
        'date', 'datetime', 'boolean', 'select', 'multi_select', 'user', 'relation', 'file',
    ],
    'allowed_filter_operators' => [
        'eq', 'neq', 'contains', 'starts_with', 'gt', 'gte', 'lt', 'lte',
        'between', 'in', 'not_in', 'is_null', 'not_null',
    ],
];
