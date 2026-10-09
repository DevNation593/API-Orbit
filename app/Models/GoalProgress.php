<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalProgress extends Model
{
    use TenantScoped;

    protected $table = 'goal_progress';

    protected $fillable = ['goal_target_id', 'snapshot_date', 'period_start', 'period_end', 'target_value', 'actual_value', 'completion_percentage', 'calculated_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date', 'period_start' => 'date', 'period_end' => 'date',
            'target_value' => 'decimal:6', 'actual_value' => 'decimal:6',
            'completion_percentage' => 'decimal:6', 'calculated_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(GoalTarget::class, 'goal_target_id');
    }
}
