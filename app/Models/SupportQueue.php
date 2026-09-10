<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportQueue extends Model
{
    use TenantScoped;

    protected $fillable = ['name', 'description', 'is_active', 'sla_policy_id', 'escalation_agent_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(SupportAgent::class, 'support_queue_agents', 'queue_id', 'agent_id')
            ->withPivot('tenant_id')->wherePivot('tenant_id', $this->tenant_id ?? app(TenantContext::class)->requireId())->withTimestamps();
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function escalationAgent(): BelongsTo
    {
        return $this->belongsTo(SupportAgent::class, 'escalation_agent_id');
    }
}
