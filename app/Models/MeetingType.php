<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MeetingType extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'created_by', 'host_user_id', 'public_id', 'name', 'description', 'duration_minutes',
        'timezone', 'buffer_before_minutes', 'buffer_after_minutes', 'minimum_notice_minutes',
        'maximum_days_ahead', 'assignment_strategy', 'assignment_cursor', 'location_type',
        'location_details', 'settings', 'active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'minimum_notice_minutes' => 'integer',
            'maximum_days_ahead' => 'integer',
            'assignment_cursor' => 'integer',
            'location_details' => 'array',
            'settings' => 'array',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MeetingType $type): void {
            $type->public_id ??= (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class)->orderBy('day_of_week')->orderBy('start_time');
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(AvailabilityExclusion::class)->orderBy('starts_at');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(MeetingBooking::class);
    }

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
