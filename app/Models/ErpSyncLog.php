<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpSyncLog extends Model
{
    use TenantScoped;

    public const UPDATED_AT = null;

    protected $fillable = ['erp_sync_id', 'attempt', 'status', 'response_status', 'request_summary', 'response_summary', 'error'];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'response_status' => 'integer', 'request_summary' => 'array', 'response_summary' => 'array'];
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(ErpSync::class, 'erp_sync_id');
    }
}
