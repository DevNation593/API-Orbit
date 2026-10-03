<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityRule extends Model
{
    use TenantScoped;

    protected $fillable = [
        'meeting_type_id', 'user_id', 'day_of_week', 'start_time', 'end_time',
        'timezone', 'valid_from', 'valid_until', 'active',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'active' => 'boolean',
        ];
    }

    public function meetingType(): BelongsTo
    {
        return $this->belongsTo(MeetingType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
