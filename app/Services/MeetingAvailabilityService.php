<?php

namespace App\Services;

use App\Models\AvailabilityExclusion;
use App\Models\AvailabilityRule;
use App\Models\CalendarConnection;
use App\Models\MeetingBooking;
use App\Models\MeetingType;
use App\Models\TenantUser;
use App\Services\Calendar\CalendarProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class MeetingAvailabilityService
{
    public function __construct(private readonly CalendarProviderManager $providers) {}

    /** @return array<string, mixed> */
    public function availability(MeetingType $meetingType, string $dateFrom, string $dateTo, string $timezone): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, $timezone)->startOfDay();
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, $timezone)->addDay()->startOfDay();
        $slots = $this->slots($meetingType, $from, $to, $timezone, false);

        return [
            'meeting_type' => [
                'public_id' => $meetingType->public_id,
                'name' => $meetingType->name,
                'description' => $meetingType->description,
                'duration_minutes' => $meetingType->duration_minutes,
                'location_type' => $meetingType->location_type,
            ],
            'timezone' => $timezone,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'slots' => $slots,
        ];
    }

    /** @return array<int, int> */
    public function hostsForStart(MeetingType $meetingType, CarbonImmutable $start, string $timezone): array
    {
        $local = $start->setTimezone($timezone);
        $slots = $this->slots(
            $meetingType,
            $local->startOfDay(),
            $local->addDay()->startOfDay(),
            $timezone,
            true,
        );
        $expected = $start->utc()->toIso8601String();
        $slot = collect($slots)->first(fn (array $candidate): bool => $candidate['starts_at'] === $expected);

        return array_map('intval', $slot['host_user_ids'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    private function slots(
        MeetingType $meetingType,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $outputTimezone,
        bool $includeHosts,
    ): array {
        $meetingType->loadMissing(['availabilityRules', 'exclusions']);
        $rules = $meetingType->availabilityRules->where('active', true);
        if (! $meetingType->active || $rules->isEmpty()) {
            return [];
        }

        $hostIds = $this->hostIds($meetingType, $rules);
        if ($hostIds === []) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $minimum = $now->addMinutes((int) $meetingType->minimum_notice_minutes);
        $maximum = $now->addDays((int) $meetingType->maximum_days_ahead);
        $fromUtc = $from->utc();
        $toUtc = $to->utc();
        $interval = max(5, (int) data_get($meetingType->settings, 'slot_interval_minutes', $meetingType->duration_minutes));
        $duration = (int) $meetingType->duration_minutes;
        $candidates = [];

        foreach ($rules as $rule) {
            $hostId = $this->hostForRule($meetingType, $rule);
            if ($hostId === null || ! in_array($hostId, $hostIds, true)) {
                continue;
            }
            $ruleTimezone = $rule->timezone ?: $meetingType->timezone;
            $date = $fromUtc->setTimezone($ruleTimezone)->subDay()->startOfDay();
            $lastDate = $toUtc->setTimezone($ruleTimezone)->addDay()->startOfDay();
            while ($date->lte($lastDate)) {
                $dateString = $date->format('Y-m-d');
                if ((int) $date->dayOfWeek === (int) $rule->day_of_week
                    && ($rule->valid_from === null || $dateString >= $rule->valid_from->format('Y-m-d'))
                    && ($rule->valid_until === null || $dateString <= $rule->valid_until->format('Y-m-d'))) {
                    $cursor = CarbonImmutable::parse($dateString.' '.$rule->start_time, $ruleTimezone);
                    $ruleEnd = CarbonImmutable::parse($dateString.' '.$rule->end_time, $ruleTimezone);
                    while ($cursor->addMinutes($duration)->lte($ruleEnd)) {
                        $start = $cursor->utc();
                        $end = $start->addMinutes($duration);
                        if ($start->gte($fromUtc) && $start->lt($toUtc) && $start->gte($minimum) && $start->lte($maximum)) {
                            $key = $start->getTimestamp().'|'.$end->getTimestamp();
                            $candidates[$key] ??= ['start' => $start, 'end' => $end, 'hosts' => []];
                            $candidates[$key]['hosts'][$hostId] = true;
                        }
                        $cursor = $cursor->addMinutes($interval);
                    }
                }
                $date = $date->addDay();
            }
        }

        if ($candidates === []) {
            return [];
        }

        $bufferBefore = (int) $meetingType->buffer_before_minutes;
        $bufferAfter = (int) $meetingType->buffer_after_minutes;
        $searchFrom = $fromUtc->subMinutes($bufferBefore + 1440);
        $searchTo = $toUtc->addMinutes($bufferAfter + 1440);
        $bookings = MeetingBooking::query()->with('meetingType:id,buffer_before_minutes,buffer_after_minutes')
            ->whereIn('host_user_id', $hostIds)->where('status', 'confirmed')
            ->where('starts_at', '<', $searchTo)->where('ends_at', '>', $searchFrom)->get();
        $exclusions = $meetingType->exclusions->filter(fn (AvailabilityExclusion $item): bool => $item->starts_at->lt($searchTo) && $item->ends_at->gt($searchFrom));
        [$externalBusy, $unavailableHosts] = $this->externalBusy($hostIds, $searchFrom, $searchTo);

        return collect($candidates)->sortBy(fn (array $slot) => $slot['start']->getTimestamp())
            ->map(function (array $slot) use (
                $bookings, $exclusions, $externalBusy, $unavailableHosts, $bufferBefore, $bufferAfter,
                $outputTimezone, $includeHosts,
            ): ?array {
                $available = collect(array_keys($slot['hosts']))->map(fn ($id): int => (int) $id)
                    ->reject(fn (int $hostId): bool => in_array($hostId, $unavailableHosts, true))
                    ->filter(fn (int $hostId): bool => ! $this->conflicts(
                        $hostId,
                        $slot['start'],
                        $slot['end'],
                        $bufferBefore,
                        $bufferAfter,
                        $bookings,
                        $exclusions,
                        $externalBusy[$hostId] ?? [],
                    ))->values();
                if ($available->isEmpty()) {
                    return null;
                }
                $result = [
                    'starts_at' => $slot['start']->toIso8601String(),
                    'ends_at' => $slot['end']->toIso8601String(),
                    'local_starts_at' => $slot['start']->setTimezone($outputTimezone)->toIso8601String(),
                    'local_ends_at' => $slot['end']->setTimezone($outputTimezone)->toIso8601String(),
                ];
                if ($includeHosts) {
                    $result['host_user_ids'] = $available->all();
                }

                return $result;
            })->filter()->take(5000)->values()->all();
    }

    /** @param Collection<int, AvailabilityRule> $rules
     * @return array<int, int>
     */
    private function hostIds(MeetingType $meetingType, Collection $rules): array
    {
        $ids = $meetingType->assignment_strategy === 'fixed'
            ? array_filter([(int) $meetingType->host_user_id])
            : $rules->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $ids = TenantUser::query()->where('tenant_id', $meetingType->tenant_id)->where('status', 'active')
            ->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $requiredProvider = match ($meetingType->location_type) {
            'google_meet' => 'google',
            'microsoft_teams' => 'microsoft',
            'zoom' => 'zoom',
            default => null,
        };
        if ($requiredProvider !== null) {
            $ids = CalendarConnection::query()->whereIn('user_id', $ids)->where('provider', $requiredProvider)
                ->where('status', 'active')->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->all();
        }

        return $ids;
    }

    private function hostForRule(MeetingType $meetingType, AvailabilityRule $rule): ?int
    {
        if ($meetingType->assignment_strategy === 'fixed') {
            return $rule->user_id === null || (int) $rule->user_id === (int) $meetingType->host_user_id
                ? (int) $meetingType->host_user_id
                : null;
        }

        return $rule->user_id === null ? null : (int) $rule->user_id;
    }

    /** @param array<int, int> $hostIds
     * @return array{0: array<int, array<int, array{start: CarbonImmutable, end: CarbonImmutable}>>, 1: array<int, int>}
     */
    private function externalBusy(array $hostIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $busy = [];
        $unavailable = [];
        $connections = CalendarConnection::query()->with('integration')->whereIn('user_id', $hostIds)
            ->where('status', 'active')->get()->groupBy('user_id');
        foreach ($connections as $hostId => $items) {
            foreach ($items as $connection) {
                try {
                    foreach ($this->providers->for($connection->provider)->busy($connection, $from, $to) as $interval) {
                        $busy[(int) $hostId][] = [
                            'start' => CarbonImmutable::parse($interval['start'])->utc(),
                            'end' => CarbonImmutable::parse($interval['end'])->utc(),
                        ];
                    }
                } catch (\Throwable $exception) {
                    $unavailable[] = (int) $hostId;
                    Log::warning('Calendar availability lookup failed.', [
                        'calendar_connection_id' => (int) $connection->id,
                        'provider' => $connection->provider,
                        'exception' => $exception::class,
                    ]);
                    break;
                }
            }
        }

        return [$busy, array_values(array_unique($unavailable))];
    }

    /** @param Collection<int, MeetingBooking> $bookings
     * @param  Collection<int, AvailabilityExclusion>  $exclusions
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $externalBusy
     */
    private function conflicts(
        int $hostId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $bufferBefore,
        int $bufferAfter,
        Collection $bookings,
        Collection $exclusions,
        array $externalBusy,
    ): bool {
        $proposedStart = $start->subMinutes($bufferBefore);
        $proposedEnd = $end->addMinutes($bufferAfter);
        foreach ($bookings->where('host_user_id', $hostId) as $booking) {
            $existingStart = CarbonImmutable::instance($booking->starts_at)
                ->subMinutes((int) $booking->meetingType?->buffer_before_minutes);
            $existingEnd = CarbonImmutable::instance($booking->ends_at)
                ->addMinutes((int) $booking->meetingType?->buffer_after_minutes);
            if ($proposedStart->lt($existingEnd) && $proposedEnd->gt($existingStart)) {
                return true;
            }
        }
        foreach ($exclusions as $exclusion) {
            if (($exclusion->user_id === null || (int) $exclusion->user_id === $hostId)
                && $proposedStart->lt($exclusion->ends_at) && $proposedEnd->gt($exclusion->starts_at)) {
                return true;
            }
        }
        foreach ($externalBusy as $interval) {
            if ($proposedStart->lt($interval['end']) && $proposedEnd->gt($interval['start'])) {
                return true;
            }
        }

        return false;
    }
}
