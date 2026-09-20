<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketComment extends Model
{
    use TenantScoped;

    protected $fillable = ['ticket_id', 'author_user_id', 'visibility', 'body', 'idempotency_key', 'payload_hash'];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
