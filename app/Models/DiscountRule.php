<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountRule extends Model
{
    use TenantScoped;

    protected $fillable = ['discount_id', 'currency_id', 'name', 'priority', 'conditions', 'match_type', 'type', 'value', 'maximum_discount', 'cumulative', 'starts_at', 'ends_at', 'active'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'conditions' => 'array', 'value' => 'decimal:6', 'maximum_discount' => 'decimal:6', 'cumulative' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean'];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
