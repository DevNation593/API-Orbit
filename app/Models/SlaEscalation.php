<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaEscalation extends Model
{
    use TenantScoped;

    protected $fillable = [
        'execution_id', 'metric', 'breached_at', 'detected_at', 'status', 'recipients',
        'attempts', 'next_attempt_at', 'reserved_until', 'reservation_token', 'last_error',
    ];

    protected $hidden = ['reserved_until', 'reservation_token', 'last_error'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array', 'attempts' => 'integer', 'breached_at' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(SlaExecution::class, 'execution_id');
    }
}
