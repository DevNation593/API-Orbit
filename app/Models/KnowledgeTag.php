<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeTag extends Model
{
    use TenantScoped;

    protected $fillable = ['created_by', 'name', 'normalized_name', 'description', 'is_active'];

    protected $hidden = ['normalized_name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeArticleVersion::class,
            'knowledge_article_version_tags',
            'tag_id',
            'version_id',
        )->withPivot('tenant_id')
            ->wherePivot('tenant_id', $this->tenant_id ?? app(TenantContext::class)->requireId())
            ->withTimestamps();
    }
}
