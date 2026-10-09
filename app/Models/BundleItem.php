<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleItem extends Model
{
    use TenantScoped;

    protected $fillable = ['bundle_id', 'product_id', 'product_variant_id', 'quantity', 'price_override', 'required', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'price_override' => 'decimal:6', 'required' => 'boolean', 'position' => 'integer'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
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
