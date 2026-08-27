<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = ['webhook_endpoint_id', 'event', 'event_id', 'payload', 'signature', 'status', 'attempts', 'last_attempt_at', 'response_status', 'response_body'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_attempt_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
