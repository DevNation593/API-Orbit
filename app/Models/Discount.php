<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Discount extends Model
{
    use TenantScoped;

    protected $fillable = [
        'currency_id', 'code', 'name', 'type', 'value', 'minimum_subtotal', 'maximum_discount',
        'usage_limit', 'usage_count', 'starts_at', 'ends_at', 'active', 'conditions',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6', 'minimum_subtotal' => 'decimal:6', 'maximum_discount' => 'decimal:6',
            'usage_limit' => 'integer', 'usage_count' => 'integer', 'starts_at' => 'datetime',
            'ends_at' => 'datetime', 'active' => 'boolean', 'conditions' => 'array',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
