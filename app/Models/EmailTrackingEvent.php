<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTrackingEvent extends Model
{
    use TenantScoped;

    protected $fillable = [
        'message_id', 'tracking_link_id', 'event', 'url_hash', 'ip', 'user_agent', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(EmailTrackingLink::class, 'tracking_link_id');
    }
}
