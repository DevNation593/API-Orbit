<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordMerge extends Model
{
    use TenantScoped;

    protected $fillable = [
        'entity_type', 'source_id', 'target_id', 'merged_by', 'source_snapshot', 'moved_relations',
    ];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'moved_relations' => 'array'];
    }

    public function merger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
