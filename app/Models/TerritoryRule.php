<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritoryRule extends Model
{
    use TenantScoped;

    protected $fillable = ['territory_id', 'name', 'entity_type', 'priority', 'conditions', 'match_type', 'stop_processing', 'active'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'conditions' => 'array', 'stop_processing' => 'boolean', 'active' => 'boolean'];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
