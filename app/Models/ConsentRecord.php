<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class ConsentRecord extends Model
{
    use TenantScoped;

    protected $fillable = ['entity_type', 'entity_id', 'channel', 'destination', 'status', 'source', 'evidence', 'ip', 'occurred_at', 'revoked_at', 'recorded_by', 'idempotency_key', 'payload_hash'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
