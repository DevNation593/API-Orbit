<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Audience extends Model
{
    use TenantScoped;

    protected $fillable = ['name', 'entity_type', 'type', 'segment_id', 'active', 'refreshed_at', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'refreshed_at' => 'datetime'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(AudienceMember::class, 'audience_id');
    }
}
