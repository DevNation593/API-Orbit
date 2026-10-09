<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    use TenantScoped;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_account_id', 'external_id', 'name', 'language', 'category', 'status', 'components', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['components' => 'array', 'synced_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
