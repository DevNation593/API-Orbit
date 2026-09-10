<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportAgent extends Model
{
    use TenantScoped;

    protected $fillable = ['user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(SupportQueue::class, 'support_queue_agents', 'agent_id', 'queue_id')
            ->withPivot('tenant_id')->wherePivot('tenant_id', $this->tenant_id ?? app(TenantContext::class)->requireId())->withTimestamps();
    }
}
