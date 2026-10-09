<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoringEvent extends Model
{
    use TenantScoped;

    protected $fillable = [
        'scoring_model_id', 'scoring_rule_id', 'lead_id', 'event_type',
        'event_key', 'points', 'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['points' => 'integer', 'metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ScoringModel::class, 'scoring_model_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ScoringRule::class, 'scoring_rule_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
