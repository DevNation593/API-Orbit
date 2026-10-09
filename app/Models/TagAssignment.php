<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TagAssignment extends Model
{
    use TenantScoped;

    protected $fillable = ['tag_id', 'taggable_type', 'taggable_id', 'assigned_by'];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}
