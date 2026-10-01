<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class CampaignEvent extends Model
{
    use TenantScoped;

    protected $fillable = ['campaign_id', 'campaign_member_id', 'event', 'idempotency_key', 'payload_hash', 'revenue', 'metadata', 'occurred_at'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['revenue' => 'decimal:6', 'metadata' => 'array', 'occurred_at' => 'datetime'];
    }
}
