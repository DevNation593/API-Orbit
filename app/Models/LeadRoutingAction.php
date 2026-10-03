<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadRoutingAction extends Model
{
    use TenantScoped;

    protected $fillable = [
        'lead_routing_rule_id', 'user_id', 'type', 'position', 'weight', 'capacity',
        'assignments_count', 'last_assigned_at', 'config',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'weight' => 'integer',
            'capacity' => 'integer',
            'assignments_count' => 'integer',
            'last_assigned_at' => 'datetime',
            'config' => 'array',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LeadRoutingRule::class, 'lead_routing_rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
