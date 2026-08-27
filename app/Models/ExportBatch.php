<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportBatch extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = ['user_id', 'entity_type', 'disk', 'path', 'status', 'filters', 'row_count', 'error'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
