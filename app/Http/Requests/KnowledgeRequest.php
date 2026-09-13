<?php

namespace App\Http\Requests;

abstract class KnowledgeRequest extends BaseApiRequest
{
    private const SERVER_CONTROLLED_FIELDS = [
        'id',
        'tenant_id',
        'public_id',
        'normalized_name',
        'created_by',
        'updated_by',
        'author_id',
        'status',
        'current_version_id',
        'published_version_id',
        'published_at',
        'archived_at',
        'created_at',
        'updated_at',
        'slug',
    ];

    final protected function serverControlledRules(): array
    {
        return array_fill_keys(self::SERVER_CONTROLLED_FIELDS, ['missing']);
    }
}
