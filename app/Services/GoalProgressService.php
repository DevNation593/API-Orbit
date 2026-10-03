<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Currency;
use App\Models\Deal;
use App\Models\Goal;
use App\Models\GoalProgress;
use App\Models\GoalTarget;
use App\Models\MeetingBooking;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Support\AuditService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class GoalProgressService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CurrencyConversionService $currencies,
        private readonly AuditService $audit,
    ) {}

    public function refresh(Goal $goal, ?CarbonInterface $asOf = null): Goal
    {
        return $this->database->transaction(function () use ($goal, $asOf): Goal {
            $goal = Goal::query()->with(['currency', 'targets'])->lockForUpdate()->findOrFail($goal->id);
            $snapshotDate = CarbonImmutable::instance($asOf ?? now())->startOfDay();
            foreach ($goal->targets as $target) {
                $this->calculate($goal, $target, $snapshotDate);
            }

            $this->audit->record(
                'goal_progress_refreshed',
                $goal,
                newValues: ['snapshot_date' => $snapshotDate->toDateString(), 'targets' => $goal->targets->count()],
            );

            return $goal->fresh([
                'currency:id,code,name,symbol,decimal_places',
                'creator:id,name,email',
                'targets' => fn ($query) => $query->orderBy('target_type')->orderBy('target_id')->orderBy('target_key'),
                'targets.progress' => fn ($query) => $query->whereDate('snapshot_date', $snapshotDate)->latest('id'),
            ]);
        });
    }

    public function refreshTarget(GoalTarget $target, ?CarbonInterface $asOf = null): GoalProgress
    {
        return $this->database->transaction(function () use ($target, $asOf): GoalProgress {
            $target = GoalTarget::query()->with('goal.currency')->lockForUpdate()->findOrFail($target->id);
            $snapshotDate = CarbonImmutable::instance($asOf ?? now())->startOfDay();
            $progress = $this->calculate($target->goal, $target, $snapshotDate);
            $this->audit->record(
                'goal_target_progress_refreshed',
                $target,
                newValues: [
                    'snapshot_date' => $snapshotDate->toDateString(),
                    'actual_value' => $progress->actual_value,
                    'completion_percentage' => $progress->completion_percentage,
                ],
            );

            return $progress;
        });
    }

    private function calculate(Goal $goal, GoalTarget $target, CarbonImmutable $snapshotDate): GoalProgress
    {
        $startsAt = CarbonImmutable::parse($goal->starts_at)->startOfDay();
        $endsAt = CarbonImmutable::parse($goal->ends_at)->endOfDay();
        $through = $snapshotDate->endOfDay()->min($endsAt);
        $actual = $through->lt($startsAt)
            ? Money::of(0)
            : $this->metricValue($goal, $target, $startsAt, $through);
        $completion = Money::compare((string) $target->target_value, '0') === 0
            ? Money::of(0)
            : Money::multiply(Money::divide($actual, (string) $target->target_value), '100');

        $progress = GoalProgress::query()
            ->where('goal_target_id', $target->id)
            ->whereDate('snapshot_date', $snapshotDate)
            ->lockForUpdate()
            ->first() ?? new GoalProgress;
        $progress->fill([
            'goal_target_id' => $target->id,
            'snapshot_date' => $snapshotDate->toDateString(),
            'period_start' => $startsAt->toDateString(),
            'period_end' => $endsAt->toDateString(),
            'target_value' => $target->target_value,
            'actual_value' => $actual,
            'completion_percentage' => $completion,
            'calculated_at' => now(),
            'metadata' => [
                'metric' => $goal->metric,
                'target_type' => $target->target_type,
                'through_date' => $through->toDateString(),
            ],
        ])->save();

        return $progress->fresh(['target.goal.currency:id,code,name,symbol,decimal_places']);
    }

    private function metricValue(Goal $goal, GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return match ($goal->metric) {
            'revenue' => $this->revenue($goal, $target, $start, $end),
            'deals_won' => $this->countDeals($target, $start, $end, true),
            'deals_created' => $this->countDeals($target, $start, $end, false),
            'calls' => $this->countActivities($target, $start, $end, true),
            'activities' => $this->countActivities($target, $start, $end, false),
            'meetings' => $this->countMeetings($target, $start, $end),
            'new_customers' => $this->countCustomers($target, $start, $end),
            'quotes' => $this->countQuotes($target, $start, $end),
            default => throw ValidationException::withMessages(['metric' => 'Unsupported goal metric.']),
        };
    }

    private function revenue(Goal $goal, GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $currency = $goal->currency;
        if (! $currency instanceof Currency) {
            throw ValidationException::withMessages(['currency_id' => 'Revenue goals require a valid currency.']);
        }

        if ($target->target_type === 'product') {
            $rows = QuoteItem::query()
                ->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
                ->where('quote_items.product_id', $target->target_id)
                ->where('quotes.status', 'accepted')
                ->whereNull('quotes.deleted_at')
                ->whereBetween('quotes.accepted_at', [$start, $end])
                ->selectRaw('quotes.currency_id as currency_id, SUM(quote_items.total) as aggregate')
                ->groupBy('quotes.currency_id')
                ->get();

            return $this->convertCurrencyRows($rows, 'currency_id', $currency);
        }

        $query = Deal::query()->where('status', 'won')->whereBetween('closed_at', [$start, $end]);
        $this->applyDealScope($query, $target);
        $rows = $query->selectRaw('deals.currency as currency_code, SUM(deals.value) as aggregate')
            ->groupBy('deals.currency')
            ->get();

        return $this->convertCurrencyRows($rows, 'currency_code', $currency);
    }

    private function countDeals(GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end, bool $won): string
    {
        $query = Deal::query();
        if ($won) {
            $query->where('status', 'won')->whereBetween('closed_at', [$start, $end]);
        } else {
            $query->whereBetween('created_at', [$start, $end]);
        }
        $this->applyDealScope($query, $target);

        return Money::of((string) $query->count());
    }

    private function countActivities(GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end, bool $callsOnly): string
    {
        $query = Activity::query()->whereBetween('occurred_at', [$start, $end]);
        if ($callsOnly) {
            $query->where(function (Builder $query): void {
                $query->whereRaw('LOWER(type) = ?', ['call'])->orWhereRaw('LOWER(type) LIKE ?', ['call.%']);
            });
        }
        $this->applyActivityScope($query, $target);

        return Money::of((string) $query->count());
    }

    private function countMeetings(GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $query = MeetingBooking::query()
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->whereBetween('starts_at', [$start, $end]);
        $this->applyMeetingScope($query, $target);

        return Money::of((string) $query->count());
    }

    private function countCustomers(GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $query = Organization::query()->whereBetween('created_at', [$start, $end]);
        $this->applyOrganizationScope($query, $target);

        return Money::of((string) $query->count());
    }

    private function countQuotes(GoalTarget $target, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $query = Quote::query()->whereBetween('created_at', [$start, $end]);
        $this->applyQuoteScope($query, $target);

        return Money::of((string) $query->count());
    }

    private function applyDealScope(Builder $query, GoalTarget $target): void
    {
        match ($target->target_type) {
            'tenant' => null,
            'user' => $query->where('deals.owner_id', $target->target_id),
            'team' => $query->where('deals.sales_team_id', $target->target_id),
            'branch' => $query->where('deals.branch_id', $target->target_id),
            'territory' => $query->where('deals.territory_id', $target->target_id),
            'industry' => $query->whereHas('organization', fn (Builder $query) => $query->whereRaw('LOWER(industry) = ?', [mb_strtolower((string) $target->target_key)])),
            'product' => $query->whereExists(function ($query) use ($target): void {
                $query->selectRaw('1')->from('quotes')
                    ->join('quote_items', 'quote_items.quote_id', '=', 'quotes.id')
                    ->whereColumn('quotes.deal_id', 'deals.id')
                    ->whereNull('quotes.deleted_at')
                    ->where('quote_items.product_id', $target->target_id);
            }),
            default => throw ValidationException::withMessages(['target_type' => 'Unsupported goal target type.']),
        };
    }

    private function applyQuoteScope(Builder $query, GoalTarget $target): void
    {
        match ($target->target_type) {
            'tenant' => null,
            'user' => $query->where('owner_id', $target->target_id),
            'team' => $query->whereHas('deal', fn (Builder $query) => $query->where('sales_team_id', $target->target_id)),
            'branch' => $query->whereHas('deal', fn (Builder $query) => $query->where('branch_id', $target->target_id)),
            'territory' => $query->where(function (Builder $query) use ($target): void {
                $query->whereHas('deal', fn (Builder $query) => $query->where('territory_id', $target->target_id))
                    ->orWhereHas('organization', fn (Builder $query) => $query->where('territory_id', $target->target_id));
            }),
            'product' => $query->whereHas('items', fn (Builder $query) => $query->where('product_id', $target->target_id)),
            'industry' => $query->whereHas('organization', fn (Builder $query) => $query->whereRaw('LOWER(industry) = ?', [mb_strtolower((string) $target->target_key)])),
            default => throw ValidationException::withMessages(['target_type' => 'Unsupported goal target type.']),
        };
    }

    private function applyActivityScope(Builder $query, GoalTarget $target): void
    {
        match ($target->target_type) {
            'tenant' => null,
            'user' => $query->where('user_id', $target->target_id),
            'team' => $query->whereIn('user_id', function ($query) use ($target): void {
                $query->select('user_id')->from('sales_team_members')
                    ->where('sales_team_id', $target->target_id)->where('active', true);
            }),
            'branch' => $query->whereIn('user_id', function ($query) use ($target): void {
                $query->select('user_id')->from('branch_members')
                    ->where('branch_id', $target->target_id)->where('active', true);
            }),
            'territory' => $query->whereIn('user_id', function ($query) use ($target): void {
                $query->select('user_id')->from('territory_members')
                    ->where('territory_id', $target->target_id)->where('active', true);
            }),
            'product', 'industry' => $this->applyActivityDealScope($query, $target),
            default => throw ValidationException::withMessages(['target_type' => 'Unsupported goal target type.']),
        };
    }

    private function applyActivityDealScope(Builder $query, GoalTarget $target): Builder
    {
        $dealQuery = Deal::query()->select('deals.id');
        $this->applyDealScope($dealQuery, $target);

        return $query->where('activityable_type', 'deal')->whereIn('activityable_id', $dealQuery);
    }

    private function applyMeetingScope(Builder $query, GoalTarget $target): void
    {
        match ($target->target_type) {
            'tenant' => null,
            'user' => $query->where('host_user_id', $target->target_id),
            'team' => $query->whereIn('host_user_id', function ($query) use ($target): void {
                $query->select('user_id')->from('sales_team_members')
                    ->where('sales_team_id', $target->target_id)->where('active', true);
            }),
            'branch' => $query->whereIn('host_user_id', function ($query) use ($target): void {
                $query->select('user_id')->from('branch_members')
                    ->where('branch_id', $target->target_id)->where('active', true);
            }),
            'territory' => $query->where(function (Builder $query) use ($target): void {
                $query->whereHas('contact', fn (Builder $query) => $query->where('territory_id', $target->target_id))
                    ->orWhereHas('lead', fn (Builder $query) => $query->where('territory_id', $target->target_id))
                    ->orWhereIn('host_user_id', function ($query) use ($target): void {
                        $query->select('user_id')->from('territory_members')
                            ->where('territory_id', $target->target_id)->where('active', true);
                    });
            }),
            'industry' => $query->where(function (Builder $query) use ($target): void {
                $industry = mb_strtolower((string) $target->target_key);
                $query->whereHas('contact.organizations', fn (Builder $query) => $query->whereRaw('LOWER(industry) = ?', [$industry]))
                    ->orWhereHas('lead.organization', fn (Builder $query) => $query->whereRaw('LOWER(industry) = ?', [$industry]));
            }),
            'product' => $query->where(function (Builder $query) use ($target): void {
                $query->whereHas('contact.deals', fn (Builder $query) => $this->applyDealScope($query, $target))
                    ->orWhereHas('lead.contact.deals', fn (Builder $query) => $this->applyDealScope($query, $target));
            }),
            default => throw ValidationException::withMessages(['target_type' => 'Unsupported goal target type.']),
        };
    }

    private function applyOrganizationScope(Builder $query, GoalTarget $target): void
    {
        match ($target->target_type) {
            'tenant' => null,
            'user' => $query->where('owner_id', $target->target_id),
            'team' => $query->whereIn('owner_id', function ($query) use ($target): void {
                $query->select('user_id')->from('sales_team_members')
                    ->where('sales_team_id', $target->target_id)->where('active', true);
            }),
            'branch' => $query->whereIn('owner_id', function ($query) use ($target): void {
                $query->select('user_id')->from('branch_members')
                    ->where('branch_id', $target->target_id)->where('active', true);
            }),
            'territory' => $query->where('territory_id', $target->target_id),
            'industry' => $query->whereRaw('LOWER(industry) = ?', [mb_strtolower((string) $target->target_key)]),
            'product' => $query->whereHas('deals', fn (Builder $query) => $this->applyDealScope($query, $target)),
            default => throw ValidationException::withMessages(['target_type' => 'Unsupported goal target type.']),
        };
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function convertCurrencyRows(Collection $rows, string $currencyField, Currency $target): string
    {
        $ids = $currencyField === 'currency_id'
            ? $rows->pluck($currencyField)->filter()->map(fn ($value) => (int) $value)->unique()->values()
            : collect();
        $codes = $currencyField === 'currency_code'
            ? $rows->pluck($currencyField)->filter()->map(fn ($value) => strtoupper((string) $value))->unique()->values()
            : collect();
        $byId = Currency::query()->whereIn('id', $ids)->get()->keyBy('id');
        $byCode = Currency::query()->whereIn('code', $codes)->get()->keyBy(fn (Currency $currency) => strtoupper($currency->code));
        $total = Money::of(0);

        foreach ($rows as $row) {
            $source = $currencyField === 'currency_id'
                ? $byId->get((int) $row->{$currencyField})
                : $byCode->get(strtoupper((string) $row->{$currencyField}));
            if (! $source instanceof Currency) {
                throw ValidationException::withMessages([
                    'currency' => 'A transaction uses a currency that is not configured for this tenant.',
                ]);
            }
            $total = Money::add($total, $this->currencies->convert((string) $row->aggregate, $source, $target));
        }

        return $total;
    }
}
