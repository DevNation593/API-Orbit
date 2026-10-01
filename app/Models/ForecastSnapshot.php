<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastSnapshot extends Model
{
    use TenantScoped;

    protected $fillable = [
        'currency_id', 'pipeline_id', 'generated_by', 'scope_type', 'scope_id', 'as_of_date',
        'period_start', 'period_end', 'pipeline', 'weighted_pipeline', 'commit', 'best_case',
        'closed_won', 'target', 'coverage', 'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date', 'period_start' => 'date', 'period_end' => 'date',
            'pipeline' => 'decimal:6', 'weighted_pipeline' => 'decimal:6', 'commit' => 'decimal:6',
            'best_case' => 'decimal:6', 'closed_won' => 'decimal:6', 'target' => 'decimal:6',
            'coverage' => 'decimal:6', 'breakdown' => 'array',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function pipelineModel(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
