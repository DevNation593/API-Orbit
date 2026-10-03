<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMember extends Model
{
    use TenantScoped;

    protected $fillable = ['campaign_id', 'entity_type', 'entity_id', 'destination', 'recipient_key', 'status', 'skip_reason', 'message_id'];

    protected function casts(): array
    {
        return [];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
