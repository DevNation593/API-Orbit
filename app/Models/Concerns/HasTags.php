<?php

namespace App\Models\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasTags
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'tag_assignments')
            ->withPivot(['id', 'tenant_id', 'assigned_by'])
            ->withTimestamps();
    }
}
