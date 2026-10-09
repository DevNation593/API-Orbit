<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceEnrollment extends Model
{
    use TenantScoped;

    protected $fillable = [
        'sequence_id', 'lead_id', 'contact_id', 'enrolled_by', 'sender_user_id', 'status',
        'current_position', 'next_run_at', 'started_at', 'last_executed_at', 'completed_at',
        'stopped_at', 'stop_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_position' => 'integer',
            'next_run_at' => 'datetime',
            'started_at' => 'datetime',
            'last_executed_at' => 'datetime',
            'completed_at' => 'datetime',
            'stopped_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SequenceExecution::class);
    }
}
