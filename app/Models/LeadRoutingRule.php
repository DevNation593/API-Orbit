<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadRoutingRule extends Model
{
    use TenantScoped;

    protected $fillable = [
        'fallback_user_id', 'name', 'strategy', 'match_type', 'priority', 'cursor',
        'config', 'active', 'stop_on_match', 'last_executed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'cursor' => 'integer',
            'config' => 'array',
            'active' => 'boolean',
            'stop_on_match' => 'boolean',
            'last_executed_at' => 'datetime',
        ];
    }

    public function fallbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_user_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(LeadRoutingCondition::class)->orderBy('position')->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(LeadRoutingAction::class)->orderBy('position')->orderBy('id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(LeadRoutingExecution::class);
    }
}
