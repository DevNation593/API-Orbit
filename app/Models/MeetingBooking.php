<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MeetingBooking extends Model
{
    use TenantScoped;

    protected $fillable = [
        'meeting_type_id', 'host_user_id', 'contact_id', 'lead_id', 'calendar_connection_id',
        'rescheduled_from_id', 'public_id', 'manage_token', 'manage_token_hash', 'idempotency_key_hash',
        'invitee_name', 'invitee_email', 'invitee_phone', 'starts_at', 'ends_at', 'timezone',
        'status', 'sync_status', 'provider', 'external_event_id', 'conference_url', 'location',
        'notes', 'sync_error', 'metadata', 'cancelled_at',
    ];

    protected $hidden = ['manage_token', 'manage_token_hash', 'idempotency_key_hash'];

    protected function casts(): array
    {
        return [
            'manage_token' => 'encrypted',
            'notes' => 'encrypted',
            'metadata' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MeetingBooking $booking): void {
            $booking->public_id ??= (string) Str::uuid();
        });
    }

    public function meetingType(): BelongsTo
    {
        return $this->belongsTo(MeetingType::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function calendarConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }
}
