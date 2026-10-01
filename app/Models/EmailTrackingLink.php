<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTrackingLink extends Model
{
    use TenantScoped;

    protected $fillable = ['message_id', 'token_hash', 'destination_url', 'kind', 'expires_at'];

    protected $hidden = ['token_hash', 'destination_url'];

    protected function casts(): array
    {
        return ['destination_url' => 'encrypted', 'expires_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailTrackingEvent::class, 'tracking_link_id');
    }
}
