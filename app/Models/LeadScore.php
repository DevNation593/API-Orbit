<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadScore extends Model
{
    use TenantScoped;

    protected $fillable = ['scoring_model_id', 'lead_id', 'score', 'classification', 'breakdown', 'calculated_at'];

    protected function casts(): array
    {
        return ['score' => 'integer', 'breakdown' => 'array', 'calculated_at' => 'datetime'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ScoringModel::class, 'scoring_model_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
