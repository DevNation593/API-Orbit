<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class ConsentLink extends Model
{
    use TenantScoped;

    protected $fillable = ['entity_type', 'entity_id', 'token_hash', 'destinations', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash', 'destinations'];

    protected function casts(): array
    {
        return ['destinations' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
