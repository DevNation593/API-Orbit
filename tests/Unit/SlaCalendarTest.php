<?php

namespace Tests\Unit;

use App\Services\SlaCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SlaCalendarTest extends TestCase
{
    public function test_business_deadlines_skip_closed_hours_and_holidays(): void
    {
        $service = app(SlaCalendarService::class);
        $calendar = $this->office();
        $from = CarbonImmutable::parse('2026-09-11 21:30:00', 'UTC');
        $this->assertSame('2026-09-14 15:30:00+00:00', $service->addWorkingSeconds($from, 7200, $calendar)->format('Y-m-d H:i:sP'));
        $this->assertSame('2026-09-11 22:00:00+00:00', $service->addWorkingSeconds($from, 1800, $calendar)->format('Y-m-d H:i:sP'));
        $calendar['holidays'] = ['2026-09-14'];
        $this->assertSame('2026-09-15 15:00:00+00:00', $service->addWorkingSeconds($from, 5400, $calendar)->format('Y-m-d H:i:sP'));
        $this->assertSame(5400, $service->workingSecondsBetween($from, CarbonImmutable::parse('2026-09-15 15:00:00', 'UTC'), $calendar));
        $this->assertSame('2026-09-11 21:30:00', $from->format('Y-m-d H:i:s'));
    }

    public function test_zero_budget_and_always_mode_preserve_real_instants(): void
    {
        $service = app(SlaCalendarService::class);
        $from = CarbonImmutable::parse('2026-09-12 10:00:00', 'America/Guayaquil');
        $this->assertSame($from->getTimestamp(), $service->addWorkingSeconds($from, 0, $this->office())->getTimestamp());
        $always = ['mode' => 'ALWAYS', 'timezone' => 'America/New_York'];
        $dst = CarbonImmutable::parse('2026-03-08 06:30:00', 'UTC');
        $this->assertSame('2026-03-08 07:30:00+00:00', $service->addWorkingSeconds($dst, 3600, $always)->format('Y-m-d H:i:sP'));
        $this->assertSame(3600, $service->workingSecondsBetween($dst, CarbonImmutable::parse('2026-03-08 07:30:00', 'UTC'), $always));
        $this->assertSame(0, $service->workingSecondsBetween($from, $from->subSecond(), $always));
    }

    public function test_split_days_and_midnight_end_count_each_open_second_once(): void
    {
        $service = app(SlaCalendarService::class);
        $calendar = ['mode' => 'BUSINESS', 'timezone' => 'UTC', 'weekly_schedule' => [
            1 => [['start' => '22:00', 'end' => '24:00']],
            2 => [['start' => '00:00', 'end' => '02:00'], ['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
        ], 'holidays' => []];
        $from = CarbonImmutable::parse('2026-09-14 23:00:00', 'UTC');
        $this->assertSame('2026-09-15 01:00:00', $service->addWorkingSeconds($from, 7200, $calendar)->format('Y-m-d H:i:s'));
        $this->assertSame(10800, $service->workingSecondsBetween($from, CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'), $calendar));
        $this->assertSame('2026-09-15 13:30:00', $service->addWorkingSeconds(CarbonImmutable::parse('2026-09-15 11:30:00', 'UTC'), 3600, $calendar)->format('Y-m-d H:i:s'));
    }

    #[DataProvider('daylightSavingCases')]
    public function test_dst_boundaries_have_independent_utc_expectations(array $schedule, string $from, int $seconds, string $deadline, int $total): void
    {
        $service = app(SlaCalendarService::class);
        $calendar = ['mode' => 'BUSINESS', 'timezone' => 'America/New_York', 'weekly_schedule' => [7 => $schedule], 'holidays' => []];
        $start = CarbonImmutable::parse($from, 'UTC');
        $this->assertSame($deadline, $service->addWorkingSeconds($start, $seconds, $calendar)->format('Y-m-d H:i:s'));
        $this->assertSame($total, $service->workingSecondsBetween($start, $start->addHours(6), $calendar));
    }

    public static function daylightSavingCases(): array
    {
        return [
            'nonexistent start becomes first valid instant' => [[['start' => '02:30', 'end' => '04:00']], '2026-03-08 06:00:00', 3600, '2026-03-08 08:00:00', 3600],
            'ambiguous start uses first occurrence' => [[['start' => '01:30', 'end' => '02:30']], '2026-11-01 05:00:00', 7200, '2026-11-01 07:30:00', 7200],
            'ambiguous end uses second occurrence' => [[['start' => '00:30', 'end' => '01:30']], '2026-11-01 04:00:00', 7200, '2026-11-01 06:30:00', 7200],
            'adjacent ambiguous intervals do not double count' => [[['start' => '01:00', 'end' => '01:30'], ['start' => '01:30', 'end' => '02:00']], '2026-11-01 05:00:00', 7200, '2026-11-01 07:00:00', 7200],
        ];
    }

    #[DataProvider('invalidCalendars')]
    public function test_normalization_rejects_invalid_calendar_definitions(array $changes): void
    {
        $this->expectException(ValidationException::class);
        app(SlaCalendarService::class)->normalize(array_replace($this->office(), $changes));
    }

    public static function invalidCalendars(): array
    {
        return [
            'mode' => [['mode' => 'AUTO']],
            'timezone' => [['timezone' => 'Invalid/Zone']],
            'offset is not IANA' => [['timezone' => '+02:00']],
            'day zero' => [['weekly_schedule' => [0 => [['start' => '09:00', 'end' => '17:00']]]]],
            'day eight' => [['weekly_schedule' => [8 => [['start' => '09:00', 'end' => '17:00']]]]],
            'empty' => [['weekly_schedule' => []]],
            'equal boundaries' => [['weekly_schedule' => [1 => [['start' => '09:00', 'end' => '09:00']]]]],
            'overnight' => [['weekly_schedule' => [1 => [['start' => '22:00', 'end' => '02:00']]]]],
            'overlap' => [['weekly_schedule' => [1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '17:00']]]]],
            'midnight start' => [['weekly_schedule' => [1 => [['start' => '24:00', 'end' => '24:00']]]]],
            'invalid end' => [['weekly_schedule' => [1 => [['start' => '09:00', 'end' => '24:01']]]]],
            'too many intervals' => [['weekly_schedule' => [1 => array_fill(0, 5, ['start' => '09:00', 'end' => '10:00'])]]],
            'duplicate holiday' => [['holidays' => ['2026-12-25', '2026-12-25']]],
            'invalid holiday' => [['holidays' => ['2026-02-30']]],
            'holiday format' => [['holidays' => ['25/12/2026']]],
            'holiday object' => [['holidays' => ['date' => '2026-12-25']]],
        ];
    }

    public function test_normalization_orders_ranges_without_changing_the_input(): void
    {
        $calendar = ['mode' => 'BUSINESS', 'timezone' => 'UTC', 'weekly_schedule' => [2 => [['start' => '13:00', 'end' => '17:00'], ['start' => '09:00', 'end' => '12:00']]], 'holidays' => ['2026-12-25', '2026-01-01']];
        $normalized = app(SlaCalendarService::class)->normalize($calendar);
        $this->assertSame('09:00', $normalized['weekly_schedule'][2][0]['start']);
        $this->assertSame(['2026-01-01', '2026-12-25'], $normalized['holidays']);
        $this->assertSame('13:00', $calendar['weekly_schedule'][2][0]['start']);
    }

    public function test_impossible_deadline_stops_at_ten_years(): void
    {
        $calendar = ['mode' => 'BUSINESS', 'timezone' => 'UTC', 'weekly_schedule' => [1 => [['start' => '09:00', 'end' => '09:01']]], 'holidays' => []];
        $this->expectException(ValidationException::class);
        app(SlaCalendarService::class)->addWorkingSeconds(CarbonImmutable::parse('2026-01-01', 'UTC'), 1000000, $calendar);
    }

    public function test_consumption_rejects_ranges_beyond_ten_years(): void
    {
        $this->expectException(ValidationException::class);
        app(SlaCalendarService::class)->workingSecondsBetween(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2036-01-02', 'UTC'), $this->office());
    }

    private function office(): array
    {
        return ['mode' => 'BUSINESS', 'timezone' => 'America/Guayaquil', 'weekly_schedule' => array_fill_keys([1, 2, 3, 4, 5], [['start' => '09:00', 'end' => '17:00']]), 'holidays' => []];
    }
}
