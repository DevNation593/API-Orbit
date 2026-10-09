<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoringRule extends Model
{
    use TenantScoped;

    protected $fillable = [
        'scoring_model_id', 'name', 'type', 'event_type', 'points', 'match_type',
        'priority', 'repeatable', 'active',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'priority' => 'integer',
            'repeatable' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ScoringModel::class, 'scoring_model_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ScoringCondition::class)->orderBy('position')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScoringEvent::class);
    }
}
