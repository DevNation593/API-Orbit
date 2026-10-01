<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    use TenantScoped;

    protected $fillable = [
        'meeting_booking_id', 'user_id', 'contact_id', 'name', 'email', 'type', 'response_status',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(MeetingBooking::class, 'meeting_booking_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
