<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceList extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['currency_id', 'name', 'description', 'priority', 'starts_at', 'ends_at', 'is_default', 'active', 'conditions'];

    protected function casts(): array
    {
        return [
            'priority' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'is_default' => 'boolean', 'active' => 'boolean', 'conditions' => 'array',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class)->orderBy('product_id')->orderByDesc('minimum_quantity');
    }
}
