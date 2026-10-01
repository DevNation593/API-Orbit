<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use TenantScoped;

    protected $fillable = [
        'price_list_id', 'product_id', 'product_variant_id', 'minimum_quantity', 'unit_price',
        'compare_at_price', 'starts_at', 'ends_at', 'active',
    ];

    protected function casts(): array
    {
        return [
            'minimum_quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'compare_at_price' => 'decimal:6',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
