<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAccount extends Model
{
    use TenantScoped;

    protected $fillable = [
        'inbox_channel_id', 'integration_id', 'user_id', 'provider', 'email_address', 'display_name',
        'signature_html', 'settings', 'sync_cursor', 'status', 'last_synced_at',
    ];

    protected $hidden = ['sync_cursor'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sync_cursor' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
