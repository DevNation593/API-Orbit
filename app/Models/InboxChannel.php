<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboxChannel extends Model
{
    use TenantScoped;

    protected $fillable = [
        'inbox_id', 'integration_id', 'channel', 'name', 'address', 'external_identifier', 'settings', 'status',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
