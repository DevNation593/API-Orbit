<?php

namespace App\Services\Calendar;

use App\Contracts\CalendarProviderInterface;
use App\Models\CalendarConnection;
use App\Models\MeetingBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleCalendarProvider implements CalendarProviderInterface
{
    public function key(): string
    {
        return 'google';
    }

    public function busy(CalendarConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendarId = $connection->external_calendar_id ?: 'primary';
        $response = $this->request($connection)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
            'timeMin' => $from->utc()->toRfc3339String(),
            'timeMax' => $to->utc()->toRfc3339String(),
            'timeZone' => 'UTC',
            'items' => [['id' => $calendarId]],
        ]);
        $this->assertSuccessful($response->successful(), $response->status(), 'availability');
        $payload = $response->json();
        $busy = is_array($payload) ? data_get($payload['calendars'][$calendarId] ?? [], 'busy', []) : [];

        return collect(is_array($busy) ? $busy : [])->filter(fn ($item) => is_array($item))
            ->map(fn (array $item): array => ['start' => (string) ($item['start'] ?? ''), 'end' => (string) ($item['end'] ?? '')])
            ->filter(fn (array $item) => $item['start'] !== '' && $item['end'] !== '')->values()->all();
    }

    public function create(MeetingBooking $booking): array
    {
        $booking->loadMissing(['meetingType', 'calendarConnection.integration', 'participants']);
        $connection = $booking->calendarConnection;
        if ($connection === null) {
            throw new RuntimeException('The booking has no Google Calendar connection.');
        }
        $calendarId = $connection->external_calendar_id ?: 'primary';
        $payload = $this->eventPayload($booking);
        if ($booking->meetingType->location_type === 'google_meet') {
            $payload['conferenceData'] = [
                'createRequest' => [
                    'requestId' => $booking->public_id,
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }
        $eventId = str_replace('-', '', mb_strtolower((string) $booking->public_id));
        $payload['id'] = $eventId;
        $request = $this->request($connection)->withQueryParameters([
            'conferenceDataVersion' => 1,
            'sendUpdates' => data_get($connection->settings, 'send_updates', 'all'),
        ]);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
        $response = $request->post($url, $payload);
        if ($response->status() === 409) {
            $response = $this->request($connection)->get($url.'/'.rawurlencode($eventId));
        }
        $this->assertSuccessful($response->successful(), $response->status(), 'event creation');
        $videoEntry = collect((array) $response->json('conferenceData.entryPoints', []))
            ->firstWhere('entryPointType', 'video');

        return [
            'external_event_id' => (string) $response->json('id'),
            'conference_url' => $response->json('hangoutLink') ?: data_get($videoEntry, 'uri'),
            'location' => $response->json('location'),
        ];
    }

    public function cancel(MeetingBooking $booking): void
    {
        $connection = $booking->calendarConnection;
        if ($connection === null || blank($booking->external_event_id)) {
            return;
        }
        $calendarId = $connection->external_calendar_id ?: 'primary';
        $response = $this->request($connection)->withQueryParameters([
            'sendUpdates' => data_get($connection->settings, 'send_updates', 'all'),
        ])->delete('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($booking->external_event_id));
        if (! $response->successful() && $response->status() !== 404) {
            $this->assertSuccessful(false, $response->status(), 'event cancellation');
        }
    }

    private function request(CalendarConnection $connection): PendingRequest
    {
        $connection->loadMissing('integration');
        if ($connection->status !== 'active' || $connection->integration?->status !== 'active'
            || $connection->integration?->provider !== 'google') {
            throw new RuntimeException('The Google Calendar connection is not active.');
        }

        return Http::acceptJson()
            ->withToken((string) data_get($connection->integration->credentials, 'access_token'))->timeout(20);
    }

    /** @return array<string, mixed> */
    private function eventPayload(MeetingBooking $booking): array
    {
        return [
            'summary' => $booking->meetingType->name,
            'description' => $booking->notes,
            'start' => ['dateTime' => $booking->starts_at->toRfc3339String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $booking->ends_at->toRfc3339String(), 'timeZone' => 'UTC'],
            'attendees' => $booking->participants->whereNotNull('email')->map(fn ($participant): array => [
                'email' => $participant->email,
                'displayName' => $participant->name,
            ])->values()->all(),
            'location' => data_get($booking->meetingType->location_details, 'label'),
            'extendedProperties' => ['private' => ['vantex_booking_id' => (string) $booking->id]],
        ];
    }

    private function assertSuccessful(bool $successful, int $status, string $operation): void
    {
        if (! $successful) {
            throw new RuntimeException("Google Calendar {$operation} failed with HTTP {$status}.");
        }
    }
}
