<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaign extends Model
{
    use TenantScoped;

    protected $fillable = ['campaign_id', 'inbox_channel_id', 'subject', 'body', 'body_html'];

    protected function casts(): array
    {
        return [];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
