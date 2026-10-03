<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use TenantScoped;

    protected $fillable = [
        'approval_process_id', 'approvable_type', 'approvable_id', 'requested_by', 'status',
        'current_step_position', 'requested_at', 'due_at', 'decided_at', 'context', 'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'current_step_position' => 'integer', 'requested_at' => 'datetime', 'due_at' => 'datetime',
            'decided_at' => 'datetime', 'context' => 'array', 'snapshot' => 'array',
        ];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(ApprovalProcess::class, 'approval_process_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(ApprovalEscalation::class);
    }
}
