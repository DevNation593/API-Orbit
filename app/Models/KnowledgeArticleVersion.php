<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeArticleVersion extends Model
{
    use TenantScoped;

    protected $fillable = [
        'article_id', 'version', 'category_id', 'author_id', 'title', 'summary',
        'body_html', 'visibility', 'seo_title', 'seo_description', 'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'version' => 'integer',
            'category_id' => 'integer',
            'author_id' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'article_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeTag::class,
            'knowledge_article_version_tags',
            'version_id',
            'tag_id',
        )->withPivot('tenant_id')
            ->wherePivot('tenant_id', $this->tenant_id ?? app(TenantContext::class)->requireId())
            ->withTimestamps();
    }
}
