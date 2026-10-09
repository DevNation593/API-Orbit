<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class ChannelWebhookEvent extends Model
{
    use TenantScoped;

    protected $fillable = [
        'provider', 'account_id', 'event_type', 'dedupe_key', 'payload', 'status',
        'attempts', 'error', 'processed_at',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
