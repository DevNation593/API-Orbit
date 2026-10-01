<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use TenantScoped;

    protected $fillable = [
        'message_id', 'file_record_id', 'provider_attachment_id', 'filename', 'mime_type', 'size', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(FileRecord::class, 'file_record_id');
    }
}
