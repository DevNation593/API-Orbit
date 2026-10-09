<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JourneyEnrollment extends Model
{
    use TenantScoped;

    protected $fillable = ['journey_id', 'journey_version_id', 'entity_type', 'entity_id', 'sender_user_id', 'idempotency_key', 'payload_hash', 'status', 'current_node', 'next_run_at', 'completed_at', 'goal_reached_at', 'stop_reason'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['next_run_at' => 'datetime', 'completed_at' => 'datetime', 'goal_reached_at' => 'datetime'];
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class, 'journey_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(JourneyVersion::class, 'journey_version_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(JourneyExecution::class, 'journey_enrollment_id')->orderBy('id');
    }
}
