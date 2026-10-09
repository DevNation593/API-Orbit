<?php

namespace App\Services;

use App\Events\MeetingBooked;
use App\Jobs\SyncMeetingBookingJob;
use App\Models\CalendarConnection;
use App\Models\Contact;
use App\Models\MeetingBooking;
use App\Models\MeetingType;
use App\Models\TenantUser;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MeetingBookingService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MeetingAvailabilityService $availability,
        private readonly DuplicateNormalizer $normalizer,
        private readonly LeadCaptureService $leadCapture,
        private readonly TimelineEventRecorder $timeline,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{booking: MeetingBooking, manage_token: string, replayed: bool}
     */
    public function book(MeetingType $meetingType, array $data): array
    {
        $keyHash = filled($data['idempotency_key'] ?? null)
            ? hash('sha256', (string) $data['idempotency_key'])
            : null;
        if ($keyHash !== null) {
            $existing = MeetingBooking::query()->where('meeting_type_id', $meetingType->id)
                ->where('idempotency_key_hash', $keyHash)->first();
            if ($existing !== null) {
                return $this->result($existing, true);
            }
        }

        $timezone = (string) $data['timezone'];
        $start = CarbonImmutable::parse((string) $data['starts_at'], $timezone)->utc();
        $hosts = $this->availability->hostsForStart($meetingType, $start, $timezone);
        if ($hosts === []) {
            throw ValidationException::withMessages(['starts_at' => 'The selected meeting slot is no longer available.']);
        }

        $booking = $this->database->transaction(function () use ($meetingType, $data, $keyHash, $timezone, $start, $hosts): MeetingBooking {
            $type = MeetingType::query()->whereKey($meetingType->id)->lockForUpdate()->firstOrFail();
            if (! $type->active) {
                throw ValidationException::withMessages(['meeting_type' => 'This meeting type is not available.']);
            }
            if ($keyHash !== null) {
                $existing = MeetingBooking::query()->where('meeting_type_id', $type->id)
                    ->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            TenantUser::query()->where('tenant_id', $type->tenant_id)->whereIn('user_id', $hosts)
                ->where('status', 'active')->lockForUpdate()->get();
            $end = $start->addMinutes((int) $type->duration_minutes);
            $availableHosts = array_values(array_filter($hosts, fn (int $hostId): bool => ! $this->hasLocalConflict($type, $hostId, $start, $end)));
            if ($availableHosts === []) {
                throw ValidationException::withMessages(['starts_at' => 'The selected meeting slot was just booked.']);
            }
            $hostId = $this->selectHost($type, $availableHosts);
            $connection = $this->calendarConnection($type, $hostId);
            $contact = $this->contact($data, $hostId);
            $token = Str::random(64);

            $booking = MeetingBooking::create([
                'meeting_type_id' => $type->id,
                'host_user_id' => $hostId,
                'contact_id' => $contact->id,
                'calendar_connection_id' => $connection?->id,
                'manage_token' => $token,
                'manage_token_hash' => hash('sha256', $token),
                'idempotency_key_hash' => $keyHash,
                'invitee_name' => $data['invitee_name'],
                'invitee_email' => $data['invitee_email'],
                'invitee_phone' => $data['invitee_phone'] ?? null,
                'starts_at' => $start,
                'ends_at' => $end,
                'timezone' => $timezone,
                'status' => 'confirmed',
                'sync_status' => $connection === null ? 'not_required' : 'pending',
                'provider' => $connection?->provider,
                'location' => data_get($type->location_details, 'label'),
                'notes' => $data['notes'] ?? null,
                'metadata' => ['source' => 'public_scheduler'],
            ]);

            $host = $connection?->user ?? TenantUser::query()->with('user')->where('tenant_id', $type->tenant_id)
                ->where('user_id', $hostId)->first()?->user;
            $booking->participants()->create([
                'user_id' => $hostId, 'name' => $host?->name, 'email' => $host?->email,
                'type' => 'host', 'response_status' => 'accepted',
            ]);
            $booking->participants()->create([
                'contact_id' => $contact->id, 'name' => $data['invitee_name'],
                'email' => $data['invitee_email'], 'type' => 'guest', 'response_status' => 'accepted',
            ]);

            if ((bool) data_get($type->settings, 'create_lead', true)) {
                $capture = $this->leadCapture->capture([
                    ...$this->names((string) $data['invitee_name']),
                    'email' => $data['invitee_email'],
                    'phone' => $data['invitee_phone'] ?? null,
                    'contact_id' => $contact->id,
                    'owner_id' => $hostId,
                    'source' => 'meeting_scheduler',
                ], 'landing', ['source' => 'meeting_scheduler'], 'meeting:'.$booking->public_id, null, 'update');
                $booking->update(['lead_id' => $capture['lead']->id]);
            }

            $this->timeline->record($contact, 'meeting.booked', [
                'meeting_booking_id' => (int) $booking->id,
                'meeting_type_id' => (int) $type->id,
                'host_user_id' => $hostId,
                'starts_at' => $start->toIso8601String(),
            ]);
            $this->audit->record('meeting_booked', $booking, newValues: $this->auditValues($booking));

            return $booking;
        });

        $replayed = ! $booking->wasRecentlyCreated;
        if (! $replayed && $booking->calendar_connection_id !== null && $booking->sync_status === 'pending') {
            SyncMeetingBookingJob::dispatch((int) $booking->tenant_id, (int) $booking->id, 'sync')->onQueue('integrations');
        }
        if (! $replayed) {
            MeetingBooked::dispatch($booking);
        }

        return $this->result($booking, $replayed);
    }

    public function assertManageToken(MeetingBooking $booking, string $token): void
    {
        if ($token === '' || ! hash_equals((string) $booking->manage_token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['manage_token' => 'The booking management token is invalid.']);
        }
    }

    public function cancel(MeetingBooking $booking, ?string $reason = null): MeetingBooking
    {
        $model = $this->database->transaction(function () use ($booking, $reason): MeetingBooking {
            $model = MeetingBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (in_array($model->status, ['cancelled', 'rescheduled'], true)) {
                return $model;
            }
            if ($model->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Only confirmed meetings can be cancelled.']);
            }
            $model->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'sync_status' => $model->external_event_id === null ? 'cancelled' : 'pending_cancel',
                'metadata' => array_replace($model->metadata ?? [], ['cancellation_reason' => $reason]),
            ]);
            if ($model->contact !== null) {
                $this->timeline->record($model->contact, 'meeting.cancelled', [
                    'meeting_booking_id' => (int) $model->id,
                    'reason' => $reason,
                ]);
            }
            $this->audit->record('meeting_cancelled', $model, newValues: $this->auditValues($model));

            return $model;
        });

        if ($model->calendar_connection_id !== null && filled($model->external_event_id)) {
            SyncMeetingBookingJob::dispatch((int) $model->tenant_id, (int) $model->id, 'cancel')->onQueue('integrations');
        }

        return $model->fresh($this->relations());
    }

    /** @param array<string, mixed> $data
     * @return array{booking: MeetingBooking, manage_token: string, replayed: bool}
     */
    public function reschedule(MeetingBooking $booking, array $data): array
    {
        if ($booking->status !== 'confirmed') {
            $existing = $booking->status === 'rescheduled'
                ? MeetingBooking::query()->where('rescheduled_from_id', $booking->id)->latest('id')->first()
                : null;
            if ($existing !== null) {
                return $this->result($existing, true);
            }
            throw ValidationException::withMessages(['status' => 'Only confirmed meetings can be rescheduled.']);
        }

        $data = array_replace([
            'invitee_name' => $booking->invitee_name,
            'invitee_email' => $booking->invitee_email,
            'invitee_phone' => $booking->invitee_phone,
            'notes' => $booking->notes,
            'timezone' => $booking->timezone,
        ], $data);
        $data['idempotency_key'] ??= 'reschedule:'.$booking->public_id.':'.hash('sha256', (string) $data['starts_at']);
        $result = $this->book($booking->meetingType, $data);

        $this->database->transaction(function () use ($booking, $result): void {
            $old = MeetingBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $new = MeetingBooking::query()->whereKey($result['booking']->id)->lockForUpdate()->firstOrFail();
            if ($old->status === 'confirmed') {
                $old->update([
                    'status' => 'rescheduled',
                    'cancelled_at' => now(),
                    'sync_status' => $old->external_event_id === null ? 'cancelled' : 'pending_cancel',
                ]);
            }
            $new->update(['rescheduled_from_id' => $old->id]);
            if ($old->contact !== null) {
                $this->timeline->record($old->contact, 'meeting.rescheduled', [
                    'old_booking_id' => (int) $old->id,
                    'new_booking_id' => (int) $new->id,
                    'starts_at' => $new->starts_at->toIso8601String(),
                ]);
            }
            $this->audit->record('meeting_rescheduled', $new, oldValues: ['booking_id' => $old->id], newValues: $this->auditValues($new));
        });

        if ($booking->calendar_connection_id !== null && filled($booking->external_event_id)) {
            SyncMeetingBookingJob::dispatch((int) $booking->tenant_id, (int) $booking->id, 'cancel')->onQueue('integrations');
        }

        return $this->result($result['booking']->fresh(), $result['replayed']);
    }

    private function hasLocalConflict(MeetingType $type, int $hostId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $proposedStart = $start->subMinutes((int) $type->buffer_before_minutes);
        $proposedEnd = $end->addMinutes((int) $type->buffer_after_minutes);
        $bookings = MeetingBooking::query()->with('meetingType:id,buffer_before_minutes,buffer_after_minutes')
            ->where('host_user_id', $hostId)->where('status', 'confirmed')
            ->where('starts_at', '<', $proposedEnd->addDay())->where('ends_at', '>', $proposedStart->subDay())
            ->lockForUpdate()->get();
        foreach ($bookings as $booking) {
            $existingStart = CarbonImmutable::instance($booking->starts_at)
                ->subMinutes((int) $booking->meetingType?->buffer_before_minutes);
            $existingEnd = CarbonImmutable::instance($booking->ends_at)
                ->addMinutes((int) $booking->meetingType?->buffer_after_minutes);
            if ($proposedStart->lt($existingEnd) && $proposedEnd->gt($existingStart)) {
                return true;
            }
        }

        return $type->exclusions()->where(function ($query) use ($hostId): void {
            $query->whereNull('user_id')->orWhere('user_id', $hostId);
        })->where('starts_at', '<', $proposedEnd)->where('ends_at', '>', $proposedStart)->lockForUpdate()->exists();
    }

    /** @param array<int, int> $hosts */
    private function selectHost(MeetingType $type, array $hosts): int
    {
        sort($hosts);
        if ($type->assignment_strategy === 'fixed') {
            if (! in_array((int) $type->host_user_id, $hosts, true)) {
                throw ValidationException::withMessages(['host' => 'The configured host is unavailable.']);
            }

            return (int) $type->host_user_id;
        }
        $index = ((int) $type->assignment_cursor) % count($hosts);
        $host = $hosts[$index];
        $type->increment('assignment_cursor');

        return $host;
    }

    private function calendarConnection(MeetingType $type, int $hostId): ?CalendarConnection
    {
        $requiredProvider = match ($type->location_type) {
            'google_meet' => 'google',
            'microsoft_teams' => 'microsoft',
            'zoom' => 'zoom',
            default => null,
        };
        $query = CalendarConnection::query()->with(['integration', 'user:id,name,email'])
            ->where('user_id', $hostId)->where('status', 'active');
        if ($requiredProvider !== null) {
            $connection = $query->where('provider', $requiredProvider)->first();
            if ($connection === null) {
                throw ValidationException::withMessages([
                    'host' => "The selected host has no active {$requiredProvider} calendar connection.",
                ]);
            }

            return $connection;
        }
        $preferred = data_get($type->settings, 'calendar_provider');

        return $query->when(is_string($preferred), fn ($builder) => $builder->orderByRaw('provider = ? desc', [$preferred]))
            ->orderBy('id')->first();
    }

    /** @param array<string, mixed> $data */
    private function contact(array $data, int $ownerId): Contact
    {
        $email = $this->normalizer->email($data['invitee_email'] ?? null);
        $phone = $this->normalizer->phone($data['invitee_phone'] ?? null);
        $contact = Contact::query()->where(function ($query) use ($email, $phone): void {
            if ($email !== null) {
                $query->where('email_normalized', $email);
            }
            if ($phone !== null) {
                $query->{$email === null ? 'where' : 'orWhere'}('phone_normalized', $phone);
            }
        })->lockForUpdate()->first();
        $names = $this->names((string) $data['invitee_name']);
        if ($contact === null) {
            return Contact::create([
                ...$names,
                'email' => $data['invitee_email'],
                'phone' => $data['invitee_phone'] ?? null,
                'owner_id' => $ownerId,
                'status' => 'active',
            ]);
        }
        $updates = [];
        foreach ([...array_keys($names), 'email', 'phone', 'owner_id'] as $field) {
            $value = match ($field) {
                'email' => $data['invitee_email'],
                'phone' => $data['invitee_phone'] ?? null,
                'owner_id' => $ownerId,
                default => $names[$field],
            };
            if (blank($contact->getAttribute($field)) && filled($value)) {
                $updates[$field] = $value;
            }
        }
        if ($updates !== []) {
            $contact->update($updates);
        }

        return $contact;
    }

    /** @return array{first_name: string, last_name: ?string} */
    private function names(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = (string) array_shift($parts);

        return ['first_name' => $first, 'last_name' => $parts === [] ? null : implode(' ', $parts)];
    }

    /** @return array{booking: MeetingBooking, manage_token: string, replayed: bool} */
    private function result(MeetingBooking $booking, bool $replayed): array
    {
        $token = (string) $booking->manage_token;

        return ['booking' => $booking->fresh($this->relations()), 'manage_token' => $token, 'replayed' => $replayed];
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'meetingType:id,public_id,name,duration_minutes,timezone,location_type',
            'host:id,name,email',
            'contact:id,first_name,last_name,email,phone',
            'lead:id,first_name,last_name,email,phone',
            'participants',
        ];
    }

    /** @return array<string, mixed> */
    private function auditValues(MeetingBooking $booking): array
    {
        return [
            'meeting_type_id' => (int) $booking->meeting_type_id,
            'host_user_id' => (int) $booking->host_user_id,
            'contact_id' => $booking->contact_id,
            'lead_id' => $booking->lead_id,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'status' => $booking->status,
            'provider' => $booking->provider,
        ];
    }
}
