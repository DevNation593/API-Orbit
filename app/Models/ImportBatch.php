<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = ['user_id', 'entity_type', 'original_filename', 'disk', 'path', 'status', 'mapping', 'summary', 'error'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'summary' => 'array'];
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
