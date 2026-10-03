<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoalTarget extends Model
{
    use TenantScoped;

    protected $fillable = ['goal_id', 'target_type', 'target_id', 'target_key', 'target_value', 'weight', 'settings'];

    protected function casts(): array
    {
        return ['target_value' => 'decimal:6', 'weight' => 'decimal:6', 'settings' => 'array'];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(GoalProgress::class)->latest('snapshot_date');
    }
}
