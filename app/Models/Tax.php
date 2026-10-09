<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tax extends Model
{
    use TenantScoped;

    protected $fillable = ['currency_id', 'code', 'name', 'calculation', 'rate', 'inclusive', 'compound', 'priority', 'active', 'settings'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6', 'inclusive' => 'boolean', 'compound' => 'boolean',
            'priority' => 'integer', 'active' => 'boolean', 'settings' => 'array',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tax')->withPivot('tenant_id')->withTimestamps();
    }
}
