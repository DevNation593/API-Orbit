<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileRecord extends Model
{
    use HasFactory, SoftDeletes, TenantScoped;

    protected $fillable = ['disk', 'path', 'filename', 'mime_type', 'size', 'uploaded_by', 'related_type', 'related_id', 'metadata'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'metadata' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
