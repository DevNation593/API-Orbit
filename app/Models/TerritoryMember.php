<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritoryMember extends Model
{
    use TenantScoped;

    protected $fillable = ['territory_id', 'user_id', 'role', 'capacity', 'active'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'active' => 'boolean'];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
