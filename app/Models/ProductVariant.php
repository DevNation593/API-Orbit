<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['product_id', 'sku', 'name', 'attributes', 'price_adjustment', 'cost', 'active', 'erp_product_id'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'price_adjustment' => 'decimal:6', 'cost' => 'decimal:6', 'active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }
}
