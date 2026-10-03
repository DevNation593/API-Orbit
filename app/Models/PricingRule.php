<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use TenantScoped;

    protected $fillable = ['currency_id', 'name', 'priority', 'target_type', 'target_id', 'conditions', 'match_type', 'action_type', 'value', 'starts_at', 'ends_at', 'active'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'target_id' => 'integer', 'conditions' => 'array', 'value' => 'decimal:6', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
