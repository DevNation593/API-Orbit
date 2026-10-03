<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use TenantScoped;

    protected $fillable = ['public_id', 'name', 'description', 'audience_id', 'sender_user_id', 'status', 'currency', 'cost', 'scheduled_at', 'launched_at', 'prepared_at', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return ['cost' => 'decimal:6', 'scheduled_at' => 'datetime', 'launched_at' => 'datetime', 'prepared_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class, 'audience_id');
    }

    public function emailCampaign(): HasOne
    {
        return $this->hasOne(EmailCampaign::class, 'campaign_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class, 'campaign_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class, 'campaign_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
