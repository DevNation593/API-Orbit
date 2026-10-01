<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyVersion extends Model
{
    use TenantScoped;

    protected $fillable = ['journey_id', 'version', 'graph', 'published_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'graph' => 'array', 'published_at' => 'datetime'];
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class, 'journey_id');
    }
}
