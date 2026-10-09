<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceExecution extends Model
{
    use TenantScoped;

    protected $fillable = [
        'sequence_enrollment_id', 'sequence_step_id', 'idempotency_key', 'attempts', 'status',
        'scheduled_for', 'started_at', 'finished_at', 'output', 'error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'output' => 'array',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(SequenceEnrollment::class, 'sequence_enrollment_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'sequence_step_id');
    }
}
