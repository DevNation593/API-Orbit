<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use TenantScoped;

    protected $fillable = [
        'subject', 'description', 'status', 'priority', 'category_id', 'queue_id',
        'assigned_agent_id', 'contact_id', 'organization_id', 'conversation_id',
        'created_by', 'resolution_summary', 'first_response_at', 'resolved_at',
        'closed_at', 'idempotency_key', 'payload_hash',
    ];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return [
            'first_response_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function sla(): HasOne
    {
        return $this->hasOne(SlaExecution::class, 'ticket_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(SupportQueue::class, 'queue_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(SupportAgent::class, 'assigned_agent_id');
    }
}
