<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeArticle extends Model
{
    use TenantScoped;

    protected $fillable = [
        'public_id', 'status', 'current_version_id', 'published_version_id',
        'created_by', 'updated_by', 'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'current_version_id' => 'integer',
            'published_version_id' => 'integer',
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticleVersion::class, 'current_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticleVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeArticleVersion::class, 'article_id')->orderByDesc('version');
    }
}
