<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['currency_id', 'created_by', 'name', 'metric', 'period_type', 'starts_at', 'ends_at', 'status', 'settings'];

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'settings' => 'array'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(GoalTarget::class);
    }
}
