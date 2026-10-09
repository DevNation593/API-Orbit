<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadRoutingExecution extends Model
{
    use TenantScoped;

    protected $fillable = [
        'lead_routing_rule_id', 'lead_id', 'selected_user_id', 'event_id', 'strategy',
        'status', 'reason', 'snapshot', 'executed_at',
    ];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'executed_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LeadRoutingRule::class, 'lead_routing_rule_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function selectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_user_id');
    }
}
