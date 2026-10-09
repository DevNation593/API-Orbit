<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'category_id', 'currency_id', 'created_by', 'type', 'sku', 'name', 'description',
        'unit_of_measure', 'base_price', 'cost', 'taxable', 'active', 'erp_product_id',
        'custom_fields', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:6', 'cost' => 'decimal:6', 'taxable' => 'boolean',
            'active' => 'boolean', 'custom_fields' => 'array', 'metadata' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('name');
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'product_tax')->withPivot('tenant_id')->withTimestamps();
    }

    public function bundle(): HasOne
    {
        return $this->hasOne(Bundle::class);
    }

    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ProductDependency::class);
    }

    public function dependenciesAsRelated(): HasMany
    {
        return $this->hasMany(ProductDependency::class, 'related_product_id');
    }
}
