<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceStep extends Model
{
    use TenantScoped;

    protected $fillable = ['sequence_id', 'position', 'type', 'delay_minutes', 'config', 'active'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_minutes' => 'integer',
            'config' => 'array',
            'active' => 'boolean',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SequenceExecution::class);
    }
}
