<?php

namespace App\Services\Calendar;

use App\Contracts\CalendarProviderInterface;
use App\Models\CalendarConnection;
use App\Models\MeetingBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ZoomMeetingProvider implements CalendarProviderInterface
{
    public function key(): string
    {
        return 'zoom';
    }

    public function busy(CalendarConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->request($connection);

        return [];
    }

    public function create(MeetingBooking $booking): array
    {
        $booking->loadMissing(['meetingType', 'calendarConnection.integration']);
        $connection = $booking->calendarConnection;
        if ($connection === null) {
            throw new RuntimeException('The booking has no Zoom connection.');
        }
        $userId = (string) data_get($connection->integration?->settings, 'user_id', 'me');
        $response = $this->request($connection)->post(
            'https://api.zoom.us/v2/users/'.rawurlencode($userId).'/meetings',
            [
                'topic' => $booking->meetingType->name,
                'type' => 2,
                'start_time' => $booking->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration' => (int) $booking->meetingType->duration_minutes,
                'timezone' => 'UTC',
                'agenda' => (string) $booking->notes,
                'settings' => [
                    'join_before_host' => false,
                    'waiting_room' => true,
                    'meeting_authentication' => false,
                ],
            ],
        );
        if (! $response->successful()) {
            throw new RuntimeException('Zoom meeting creation failed with HTTP '.$response->status().'.');
        }

        return [
            'external_event_id' => (string) $response->json('id'),
            'conference_url' => $response->json('join_url'),
            'location' => 'Zoom',
        ];
    }

    public function cancel(MeetingBooking $booking): void
    {
        if ($booking->calendarConnection === null || blank($booking->external_event_id)) {
            return;
        }
        $response = $this->request($booking->calendarConnection)->delete(
            'https://api.zoom.us/v2/meetings/'.rawurlencode($booking->external_event_id),
        );
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Zoom meeting cancellation failed with HTTP '.$response->status().'.');
        }
    }

    private function request(CalendarConnection $connection): PendingRequest
    {
        $connection->loadMissing('integration');
        if ($connection->status !== 'active' || $connection->integration?->status !== 'active'
            || $connection->integration?->provider !== 'zoom') {
            throw new RuntimeException('The Zoom connection is not active.');
        }

        return Http::acceptJson()
            ->withToken((string) data_get($connection->integration->credentials, 'access_token'))->timeout(20);
    }
}
