<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bundle extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['product_id', 'name', 'description', 'pricing_method', 'fixed_price', 'active', 'settings'];

    protected function casts(): array
    {
        return ['fixed_price' => 'decimal:6', 'active' => 'boolean', 'settings' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class)->orderBy('position')->orderBy('id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BundleRule::class);
    }
}
