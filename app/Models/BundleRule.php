<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleRule extends Model
{
    use TenantScoped;

    protected $fillable = ['bundle_id', 'name', 'priority', 'conditions', 'match_type', 'minimum_quantity', 'maximum_quantity', 'active'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'conditions' => 'array', 'minimum_quantity' => 'decimal:6', 'maximum_quantity' => 'decimal:6', 'active' => 'boolean'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }
}
