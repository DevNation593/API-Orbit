<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segment extends Model
{
    use TenantScoped;

    protected $fillable = ['name', 'description', 'entity_type', 'entity_definition_id', 'definition', 'active', 'revision', 'refreshed_at', 'created_by'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'active' => 'boolean', 'revision' => 'integer', 'refreshed_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(SegmentMember::class, 'segment_id');
    }
}
