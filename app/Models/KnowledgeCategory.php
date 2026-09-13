<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeCategory extends Model
{
    use TenantScoped;

    protected $fillable = [
        'created_by', 'name', 'normalized_name', 'description', 'position', 'is_active',
    ];

    protected $hidden = ['normalized_name'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeArticleVersion::class, 'category_id');
    }
}
