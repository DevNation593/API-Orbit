<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteItem extends Model
{
    use TenantScoped;

    protected $fillable = [
        'quote_id', 'parent_item_id', 'product_id', 'product_variant_id', 'position', 'item_type',
        'sku', 'name', 'description', 'unit_of_measure', 'quantity', 'unit_price', 'subtotal',
        'discount_total', 'tax_total', 'total', 'taxable', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer', 'quantity' => 'decimal:6', 'unit_price' => 'decimal:6',
            'subtotal' => 'decimal:6', 'discount_total' => 'decimal:6', 'tax_total' => 'decimal:6',
            'total' => 'decimal:6', 'taxable' => 'boolean', 'metadata' => 'array',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(QuoteTax::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(QuoteDiscount::class);
    }
}
