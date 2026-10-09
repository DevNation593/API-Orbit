<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpSync extends Model
{
    use TenantScoped;

    protected $fillable = ['integration_id', 'entity_type', 'entity_id', 'operation', 'idempotency_key', 'status', 'attempts', 'external_id', 'error', 'last_attempt_at', 'completed_at', 'metadata'];

    protected function casts(): array
    {
        return ['entity_id' => 'integer', 'attempts' => 'integer', 'last_attempt_at' => 'datetime', 'completed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ErpSyncLog::class)->orderBy('attempt');
    }
}
