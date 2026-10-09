<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarConnection extends Model
{
    use TenantScoped;

    protected $fillable = [
        'user_id', 'integration_id', 'provider', 'external_calendar_id', 'timezone',
        'settings', 'sync_cursor', 'status', 'last_synced_at',
    ];

    protected $hidden = ['sync_cursor'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sync_cursor' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(MeetingBooking::class);
    }
}
