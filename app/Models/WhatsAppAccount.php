<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    use TenantScoped;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'inbox_channel_id', 'integration_id', 'business_account_id', 'phone_number_id',
        'display_phone_number', 'verify_token_hash', 'settings', 'status', 'last_synced_at',
    ];

    protected $hidden = ['verify_token_hash'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }
}
