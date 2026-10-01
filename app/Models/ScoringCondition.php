<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoringCondition extends Model
{
    use TenantScoped;

    protected $fillable = ['scoring_rule_id', 'field', 'operator', 'value', 'position'];

    protected function casts(): array
    {
        return ['value' => 'array', 'position' => 'integer'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ScoringRule::class, 'scoring_rule_id');
    }
}
