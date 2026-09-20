<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SlaCalendarService
{
    public function normalize(array $calendar): array
    {
        $data = Validator::make($calendar, [
            'mode' => ['required', Rule::in(['ALWAYS', 'BUSINESS'])],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'weekly_schedule' => ['sometimes', 'array'],
            'holidays' => ['sometimes', 'array', 'list', 'max:366'],
            'holidays.*' => ['required', 'string', 'date_format:Y-m-d', 'distinct:strict'],
        ])->validate();
        $schedule = [];
        $intervalCount = 0;
        foreach ($data['weekly_schedule'] ?? [] as $day => $intervals) {
            if (! preg_match('/^[1-7]$/D', (string) $day)
                || ! is_array($intervals) || ! array_is_list($intervals) || count($intervals) > 4) {
                $this->invalid('weekly_schedule', 'Use ISO days 1-7 with at most four intervals per day.');
            }
            $normalized = [];
            foreach ($intervals as $interval) {
                if (! is_array($interval) || array_diff(array_keys($interval), ['start', 'end'])
                    || ! is_string($interval['start'] ?? null) || ! is_string($interval['end'] ?? null)
                    || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $interval['start'])
                    || ! preg_match('/^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/D', $interval['end'])
                    || $interval['end'] <= $interval['start']) {
                    $this->invalid('weekly_schedule', 'Intervals require start < end in HH:MM format; 24:00 is an end only.');
                }
                $normalized[] = ['start' => $interval['start'], 'end' => $interval['end']];
            }
            usort($normalized, fn (array $left, array $right): int => strcmp($left['start'], $right['start']));
            $previousEnd = null;
            foreach ($normalized as $interval) {
                if ($previousEnd !== null && $interval['start'] < $previousEnd) {
                    $this->invalid('weekly_schedule', 'Working intervals cannot overlap.');
                }
                $previousEnd = $interval['end'];
            }
            $schedule[(int) $day] = $normalized;
            $intervalCount += count($normalized);
        }
        if ($data['mode'] === 'BUSINESS' && $intervalCount === 0) {
            $this->invalid('weekly_schedule', 'A business calendar needs at least one weekly interval.');
        }
        ksort($schedule);
        $holidays = $data['holidays'] ?? [];
        sort($holidays);

        return [
            'mode' => $data['mode'], 'timezone' => $data['timezone'],
            'weekly_schedule' => $data['mode'] === 'BUSINESS' ? $schedule : [],
            'holidays' => $data['mode'] === 'BUSINESS' ? $holidays : [],
        ];
    }

    public function addWorkingSeconds(CarbonImmutable $from, int $seconds, array $calendar): CarbonImmutable
    {
        $calendar = $this->normalize($calendar);
        if ($seconds < 0) {
            $this->invalid('seconds', 'The SLA budget cannot be negative.');
        }
        $from = $from->utc();
        if ($seconds === 0) {
            return $from;
        }
        $limit = $from->addYearsNoOverflow(10);
        foreach ($this->intervals($from, $limit, $calendar) as [$start, $end]) {
            $available = $end - $start;
            if ($seconds <= $available) {
                return CarbonImmutable::createFromTimestampUTC($start + $seconds);
            }
            $seconds -= $available;
        }
        $this->invalid('calendar', 'The SLA deadline exceeds the ten-year calculation horizon.');
    }

    public function workingSecondsBetween(CarbonImmutable $from, CarbonImmutable $to, array $calendar): int
    {
        $calendar = $this->normalize($calendar);
        $from = $from->utc();
        $to = $to->utc();
        if ($to->lte($from)) {
            return 0;
        }
        if ($to->gt($from->addYearsNoOverflow(10))) {
            $this->invalid('calendar', 'The SLA interval exceeds the ten-year calculation horizon.');
        }
        $seconds = 0;
        foreach ($this->intervals($from, $to, $calendar) as [$start, $end]) {
            $seconds += $end - $start;
        }

        return $seconds;
    }

    /** @return Generator<int, array{int, int}> */
    private function intervals(CarbonImmutable $from, CarbonImmutable $until, array $calendar): Generator
    {
        $minimum = $from->getTimestamp();
        $maximum = $until->getTimestamp();
        if ($calendar['mode'] === 'ALWAYS') {
            yield [$minimum, $maximum];

            return;
        }
        $zone = new DateTimeZone($calendar['timezone']);
        // Iterate civil dates in UTC so midnight DST jumps cannot skip a local date.
        $date = CarbonImmutable::parse($from->setTimezone($zone)->format('Y-m-d'), 'UTC');
        $lastDate = $until->setTimezone($zone)->format('Y-m-d');
        $holidays = array_fill_keys($calendar['holidays'], true);
        $pending = null;
        while ($date->format('Y-m-d') <= $lastDate) {
            $localDate = $date->format('Y-m-d');
            $ranges = [];
            if (! isset($holidays[$localDate])) {
                foreach ($calendar['weekly_schedule'][$date->isoWeekday()] ?? [] as $interval) {
                    $start = max($minimum, $this->boundary($localDate, $interval['start'], $zone, false));
                    $end = min($maximum, $this->boundary($localDate, $interval['end'], $zone, true));
                    if ($end > $start) {
                        $ranges[] = [$start, $end];
                    }
                }
            }
            usort($ranges, fn (array $left, array $right): int => $left[0] <=> $right[0]);
            foreach ($ranges as [$start, $end]) {
                // Ambiguous endpoints may expand adjacent local ranges into overlapping UTC ranges.
                if ($pending !== null && $start <= $pending[1]) {
                    $pending = [min($pending[0], $start), max($pending[1], $end)];
                } else {
                    if ($pending !== null) {
                        yield $pending;
                    }
                    $pending = [$start, $end];
                }
            }
            $date = $date->addDay();
        }
        if ($pending !== null) {
            yield $pending;
        }
    }

    private function boundary(string $date, string $clock, DateTimeZone $zone, bool $isEnd): int
    {
        $minutes = ((int) substr($clock, 0, 2)) * 60 + (int) substr($clock, 3, 2);
        $wall = CarbonImmutable::parse($date, 'UTC')->addMinutes($minutes);
        $wallTimestamp = $wall->getTimestamp();
        $transitions = $zone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800) ?: [];
        $candidates = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $candidate = $wallTimestamp - $offset;
            if (CarbonImmutable::createFromTimestampUTC($candidate)->setTimezone($zone)->format('Y-m-d H:i:s') === $wall->format('Y-m-d H:i:s')) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates !== []) {
            return $isEnd ? max($candidates) : min($candidates);
        }
        for ($index = 1; $index < count($transitions); $index++) {
            $transition = $transitions[$index];
            $oldOffset = $transitions[$index - 1]['offset'];
            if ($transition['offset'] > $oldOffset
                && $wallTimestamp >= $transition['ts'] + $oldOffset
                && $wallTimestamp < $transition['ts'] + $transition['offset']) {
                return $transition['ts'];
            }
        }
        $this->invalid('calendar', 'This local calendar boundary cannot be resolved.');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
