<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaExecution extends Model
{
    use TenantScoped;

    protected $fillable = [
        'ticket_id', 'snapshot', 'status', 'first_response_due_at', 'first_response_at',
        'resolution_due_at', 'last_resolution_due_at', 'resolved_at', 'paused_at',
        'resolution_anchor_at', 'resolution_remaining_seconds', 'first_response_breached',
        'resolution_breached', 'first_response_breached_at', 'resolution_breached_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'resolution_remaining_seconds' => 'integer',
            'first_response_breached' => 'boolean', 'resolution_breached' => 'boolean',
            'first_response_due_at' => 'immutable_datetime', 'first_response_at' => 'immutable_datetime',
            'resolution_due_at' => 'immutable_datetime', 'last_resolution_due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime',
            'resolution_anchor_at' => 'immutable_datetime', 'first_response_breached_at' => 'immutable_datetime',
            'resolution_breached_at' => 'immutable_datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(SlaEscalation::class, 'execution_id');
    }
}
