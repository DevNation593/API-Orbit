<?php

namespace App\Services\Calendar;

use App\Contracts\CalendarProviderInterface;
use App\Models\CalendarConnection;
use App\Models\MeetingBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MicrosoftCalendarProvider implements CalendarProviderInterface
{
    public function key(): string
    {
        return 'microsoft';
    }

    public function busy(CalendarConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $connection->loadMissing('user');
        $schedule = (string) data_get($connection->settings, 'schedule_address', $connection->user?->email);
        $response = $this->request($connection)->post('https://graph.microsoft.com/v1.0/me/calendar/getSchedule', [
            'schedules' => [$schedule],
            'startTime' => ['dateTime' => $from->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'endTime' => ['dateTime' => $to->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'availabilityViewInterval' => 15,
        ]);
        $this->assertSuccessful($response->successful(), $response->status(), 'availability');

        return collect((array) $response->json('value.0.scheduleItems', []))->filter(fn ($item) => is_array($item))
            ->map(fn (array $item): array => [
                'start' => CarbonImmutable::parse((string) data_get($item, 'start.dateTime'), (string) data_get($item, 'start.timeZone', 'UTC'))->utc()->toRfc3339String(),
                'end' => CarbonImmutable::parse((string) data_get($item, 'end.dateTime'), (string) data_get($item, 'end.timeZone', 'UTC'))->utc()->toRfc3339String(),
            ])->values()->all();
    }

    public function create(MeetingBooking $booking): array
    {
        $booking->loadMissing(['meetingType', 'calendarConnection.integration', 'participants']);
        $connection = $booking->calendarConnection;
        if ($connection === null) {
            throw new RuntimeException('The booking has no Microsoft Calendar connection.');
        }
        $payload = [
            'subject' => $booking->meetingType->name,
            'body' => ['contentType' => 'text', 'content' => (string) $booking->notes],
            'start' => ['dateTime' => $booking->starts_at->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $booking->ends_at->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'attendees' => $booking->participants->whereNotNull('email')->map(fn ($participant): array => [
                'emailAddress' => ['address' => $participant->email, 'name' => $participant->name],
                'type' => 'required',
            ])->values()->all(),
            'transactionId' => $booking->public_id,
        ];
        if ($booking->meetingType->location_type === 'microsoft_teams') {
            $payload['isOnlineMeeting'] = true;
            $payload['onlineMeetingProvider'] = 'teamsForBusiness';
        } elseif (filled(data_get($booking->meetingType->location_details, 'label'))) {
            $payload['location'] = ['displayName' => data_get($booking->meetingType->location_details, 'label')];
        }
        $response = $this->request($connection)->post('https://graph.microsoft.com/v1.0/me/events', $payload);
        $this->assertSuccessful($response->successful(), $response->status(), 'event creation');

        return [
            'external_event_id' => (string) $response->json('id'),
            'conference_url' => $response->json('onlineMeeting.joinUrl') ?: $response->json('onlineMeetingUrl'),
            'location' => $response->json('location.displayName'),
        ];
    }

    public function cancel(MeetingBooking $booking): void
    {
        $connection = $booking->calendarConnection;
        if ($connection === null || blank($booking->external_event_id)) {
            return;
        }
        $response = $this->request($connection)->delete(
            'https://graph.microsoft.com/v1.0/me/events/'.rawurlencode($booking->external_event_id),
        );
        if (! $response->successful() && $response->status() !== 404) {
            $this->assertSuccessful(false, $response->status(), 'event cancellation');
        }
    }

    private function request(CalendarConnection $connection): PendingRequest
    {
        $connection->loadMissing('integration');
        if ($connection->status !== 'active' || $connection->integration?->status !== 'active'
            || $connection->integration?->provider !== 'microsoft') {
            throw new RuntimeException('The Microsoft Calendar connection is not active.');
        }

        return Http::acceptJson()
            ->withToken((string) data_get($connection->integration->credentials, 'access_token'))->timeout(20);
    }

    private function assertSuccessful(bool $successful, int $status, string $operation): void
    {
        if (! $successful) {
            throw new RuntimeException("Microsoft Calendar {$operation} failed with HTTP {$status}.");
        }
    }
}
