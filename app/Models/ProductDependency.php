<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDependency extends Model
{
    use TenantScoped;

    protected $fillable = ['product_id', 'related_product_id', 'relation', 'minimum_quantity', 'conditions', 'active'];

    protected function casts(): array
    {
        return ['minimum_quantity' => 'decimal:6', 'conditions' => 'array', 'active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
