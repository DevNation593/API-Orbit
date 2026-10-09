<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TerritoryAssignment extends Model
{
    use TenantScoped;

    protected $fillable = ['territory_id', 'territory_rule_id', 'assignable_type', 'assignable_id', 'source', 'assigned_by', 'assigned_at', 'metadata'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'metadata' => 'array'];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TerritoryRule::class, 'territory_rule_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
