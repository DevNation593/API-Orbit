<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Deal;
use App\Models\ForecastSnapshot;
use App\Models\GoalTarget;
use App\Models\SalesTeam;
use App\Models\TenantUser;
use App\Support\AuditService;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ForecastService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CurrencyConversionService $currencies,
        private readonly AuditService $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $context = $this->context($filters);
        $rows = $this->dealRows($filters, null);
        $targetRows = $this->targetRows($context, 'tenant');

        return $this->buildMetrics($rows, $targetRows, $context, 'tenant', null, 'Tenant');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function users(array $filters): array
    {
        $context = $this->context($filters);
        $rows = $this->dealRows($filters, 'user')->groupBy(fn ($row) => (string) $row->scope_id);
        $targets = $this->targetRows($context, 'user')->groupBy(fn ($row) => (string) $row->scope_id);
        $members = TenantUser::query()
            ->where('tenant_id', $this->tenantContext->requireId())
            ->where('status', 'active')
            ->when(isset($filters['owner_id']), fn ($query) => $query->where('user_id', $filters['owner_id']))
            ->with('user:id,name,email')
            ->orderBy('user_id')
            ->get();

        return $members->map(function (TenantUser $member) use ($rows, $targets, $context): array {
            $id = (int) $member->user_id;

            return $this->buildMetrics(
                $rows->get((string) $id, collect()),
                $targets->get((string) $id, collect()),
                $context,
                'user',
                $id,
                $member->user?->name ?? 'User '.$id,
            );
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function teams(array $filters): array
    {
        $context = $this->context($filters);
        $rows = $this->dealRows($filters, 'team')->groupBy(fn ($row) => (string) $row->scope_id);
        $targets = $this->targetRows($context, 'team')->groupBy(fn ($row) => (string) $row->scope_id);
        $teams = SalesTeam::query()
            ->where('active', true)
            ->when(isset($filters['team_id']), fn (Builder $query) => $query->whereKey($filters['team_id']))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $teams->map(function (SalesTeam $team) use ($rows, $targets, $context): array {
            $id = (int) $team->id;

            return $this->buildMetrics(
                $rows->get((string) $id, collect()),
                $targets->get((string) $id, collect()),
                $context,
                'team',
                $id,
                $team->name,
            );
        })->values()->all();
    }

    /** @param array<string, mixed> $filters */
    public function snapshot(array $filters, int $userId): ForecastSnapshot
    {
        return $this->database->transaction(function () use ($filters, $userId): ForecastSnapshot {
            $summary = $this->summary($filters);
            $snapshot = ForecastSnapshot::create([
                'currency_id' => $summary['currency']['id'],
                'pipeline_id' => $summary['filters']['pipeline_id'],
                'generated_by' => $userId,
                'scope_type' => 'tenant',
                'scope_id' => null,
                'as_of_date' => $summary['as_of_date'],
                'period_start' => $summary['period']['start'],
                'period_end' => $summary['period']['end'],
                'pipeline' => $summary['pipeline'],
                'weighted_pipeline' => $summary['weighted_pipeline'],
                'commit' => $summary['commit'],
                'best_case' => $summary['best_case'],
                'closed_won' => $summary['closed_won'],
                'target' => $summary['target'],
                'coverage' => $summary['coverage'],
                'breakdown' => [
                    'filters' => $summary['filters'],
                    'deal_count' => $summary['deal_count'],
                    'currency' => $summary['currency'],
                ],
            ]);
            $this->audit->record('forecast_snapshot_created', $snapshot, newValues: $snapshot->getAttributes());

            return $snapshot->fresh(['currency:id,code,name,symbol,decimal_places', 'generator:id,name,email', 'pipelineModel:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{start: CarbonImmutable, end: CarbonImmutable, as_of: CarbonImmutable, currency: Currency, filters: array<string, mixed>}
     */
    private function context(array $filters): array
    {
        $start = CarbonImmutable::parse($filters['starts_at'] ?? now()->startOfMonth())->startOfDay();
        $end = CarbonImmutable::parse($filters['ends_at'] ?? now()->endOfMonth())->endOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['ends_at' => 'The forecast end must not be before its start.']);
        }
        $asOf = CarbonImmutable::parse($filters['as_of_date'] ?? now())->startOfDay();
        $currency = isset($filters['currency_id'])
            ? Currency::query()->where('active', true)->findOrFail($filters['currency_id'])
            : Currency::query()->where('is_base', true)->where('active', true)->first();
        if (! $currency instanceof Currency) {
            throw ValidationException::withMessages(['currency_id' => 'Configure an active base currency before generating a forecast.']);
        }

        return [
            'start' => $start,
            'end' => $end,
            'as_of' => $asOf,
            'currency' => $currency,
            'filters' => [
                'pipeline_id' => isset($filters['pipeline_id']) ? (int) $filters['pipeline_id'] : null,
                'owner_id' => isset($filters['owner_id']) ? (int) $filters['owner_id'] : null,
                'team_id' => isset($filters['team_id']) ? (int) $filters['team_id'] : null,
                'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
                'territory_id' => isset($filters['territory_id']) ? (int) $filters['territory_id'] : null,
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function dealRows(array $filters, ?string $scope): Collection
    {
        $context = $this->context($filters);
        $query = Deal::query()
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
            ->where(function (Builder $query) use ($context): void {
                $query->where(function (Builder $query) use ($context): void {
                    $query->where('deals.status', 'open')
                        ->where('deals.forecast_category', '!=', 'omitted')
                        ->whereBetween('deals.expected_close_date', [
                            $context['start']->toDateString(),
                            $context['end']->toDateString(),
                        ]);
                })->orWhere(function (Builder $query) use ($context): void {
                    $query->where('deals.status', 'won')
                        ->whereBetween('deals.closed_at', [$context['start'], $context['end']])
                        ->where('deals.closed_at', '<=', $context['as_of']->endOfDay());
                });
            })
            ->where('deals.created_at', '<=', $context['as_of']->endOfDay());
        $this->applyFilters($query, $filters);

        $scopeColumn = match ($scope) {
            'user' => 'deals.owner_id',
            'team' => 'deals.sales_team_id',
            default => null,
        };
        if ($scopeColumn !== null) {
            $query->whereNotNull($scopeColumn);
        }
        $selectScope = $scopeColumn === null ? 'NULL as scope_id' : $scopeColumn.' as scope_id';

        return $query->selectRaw($selectScope)
            ->selectRaw('deals.currency as currency_code, deals.status, deals.forecast_category, pipeline_stages.probability')
            ->selectRaw('COUNT(deals.id) as deal_count, SUM(deals.value) as aggregate')
            ->groupBy(array_values(array_filter([
                $scopeColumn,
                'deals.currency',
                'deals.status',
                'deals.forecast_category',
                'pipeline_stages.probability',
            ])))
            ->get();
    }

    /** @param array<string, mixed> $context */
    private function targetRows(array $context, string $scope): Collection
    {
        $query = GoalTarget::query()
            ->join('goals', 'goals.id', '=', 'goal_targets.goal_id')
            ->whereNull('goals.deleted_at')
            ->where('goals.metric', 'revenue')
            ->where('goals.status', 'active')
            ->where('goals.starts_at', '<=', $context['end']->toDateString())
            ->where('goals.ends_at', '>=', $context['start']->toDateString())
            ->where('goal_targets.target_type', $scope);
        if ($scope === 'tenant') {
            $query->whereNull('goal_targets.target_id');
            $selectScope = 'NULL as scope_id';
            $groupScope = null;
        } else {
            $query->whereNotNull('goal_targets.target_id');
            $selectScope = 'goal_targets.target_id as scope_id';
            $groupScope = 'goal_targets.target_id';
        }

        return $query->selectRaw($selectScope)
            ->selectRaw('goals.currency_id, SUM(goal_targets.target_value) as aggregate')
            ->groupBy(array_values(array_filter([$groupScope, 'goals.currency_id'])))
            ->get();
    }

    /**
     * @param  Collection<int, object>  $dealRows
     * @param  Collection<int, object>  $targetRows
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildMetrics(
        Collection $dealRows,
        Collection $targetRows,
        array $context,
        string $scopeType,
        ?int $scopeId,
        string $scopeName,
    ): array {
        $currency = $context['currency'];
        $sourceCurrencies = $this->loadCurrencies($dealRows, $targetRows);
        $metrics = [
            'pipeline' => Money::of(0),
            'weighted_pipeline' => Money::of(0),
            'commit' => Money::of(0),
            'best_case' => Money::of(0),
            'closed_won' => Money::of(0),
        ];
        $dealCount = 0;

        foreach ($dealRows as $row) {
            $source = $sourceCurrencies['codes']->get(strtoupper((string) $row->currency_code));
            if (! $source instanceof Currency) {
                throw ValidationException::withMessages(['currency' => 'A deal uses a currency that is not configured for this tenant.']);
            }
            $amount = $this->currencies->convert((string) $row->aggregate, $source, $currency);
            $dealCount += (int) $row->deal_count;
            if ($row->status === 'won') {
                $metrics['closed_won'] = Money::add($metrics['closed_won'], $amount);

                continue;
            }
            $metrics['pipeline'] = Money::add($metrics['pipeline'], $amount);
            $weighted = Money::divide(Money::multiply($amount, (string) $row->probability), '100');
            $metrics['weighted_pipeline'] = Money::add($metrics['weighted_pipeline'], $weighted);
            if ($row->forecast_category === 'commit') {
                $metrics['commit'] = Money::add($metrics['commit'], $amount);
            } elseif ($row->forecast_category === 'best_case') {
                $metrics['best_case'] = Money::add($metrics['best_case'], $amount);
            }
        }

        $target = Money::of(0);
        foreach ($targetRows as $row) {
            $source = $sourceCurrencies['ids']->get((int) $row->currency_id);
            if (! $source instanceof Currency) {
                throw ValidationException::withMessages(['currency' => 'A goal uses a currency that is not configured for this tenant.']);
            }
            $target = Money::add($target, $this->currencies->convert((string) $row->aggregate, $source, $currency));
        }
        $coverage = Money::compare($target, '0') === 0 ? Money::of(0) : Money::divide($metrics['pipeline'], $target);

        return [
            'scope' => ['type' => $scopeType, 'id' => $scopeId, 'name' => $scopeName],
            'as_of_date' => $context['as_of']->toDateString(),
            'period' => ['start' => $context['start']->toDateString(), 'end' => $context['end']->toDateString()],
            'currency' => [
                'id' => (int) $currency->id,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->decimal_places,
            ],
            ...$metrics,
            'target' => $target,
            'coverage' => $coverage,
            'deal_count' => $dealCount,
            'filters' => $context['filters'],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach ([
            'pipeline_id' => 'deals.pipeline_id',
            'owner_id' => 'deals.owner_id',
            'team_id' => 'deals.sales_team_id',
            'branch_id' => 'deals.branch_id',
            'territory_id' => 'deals.territory_id',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
    }

    /**
     * @param  Collection<int, object>  $dealRows
     * @param  Collection<int, object>  $targetRows
     * @return array{codes: Collection<string, Currency>, ids: Collection<int, Currency>}
     */
    private function loadCurrencies(Collection $dealRows, Collection $targetRows): array
    {
        $codes = $dealRows->pluck('currency_code')->filter()->map(fn ($code) => strtoupper((string) $code))->unique()->values();
        $ids = $targetRows->pluck('currency_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $currencies = Currency::query()
            ->where(function (Builder $query) use ($codes, $ids): void {
                $query->whereIn('code', $codes)->orWhereIn('id', $ids);
            })
            ->get();

        return [
            'codes' => $currencies->keyBy(fn (Currency $currency) => strtoupper($currency->code)),
            'ids' => $currencies->keyBy(fn (Currency $currency) => (int) $currency->id),
        ];
    }
}
