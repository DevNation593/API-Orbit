<?php

namespace App\Contracts;

use App\Models\CalendarConnection;
use App\Models\MeetingBooking;
use Carbon\CarbonImmutable;

interface CalendarProviderInterface
{
    public function key(): string;

    /** @return array<int, array{start: string, end: string}> */
    public function busy(CalendarConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array;

    /** @return array{external_event_id: string, conference_url: ?string, location: ?string} */
    public function create(MeetingBooking $booking): array;

    public function cancel(MeetingBooking $booking): void;
}
