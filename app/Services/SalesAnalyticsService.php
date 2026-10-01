<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Product;
use App\Models\QuoteItem;
use App\Models\Territory;
use App\Models\User;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class SalesAnalyticsService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CurrencyConversionService $currencies,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $context = $this->context($filters);
        $won = $this->closedDeals($filters, $context, 'won');
        $lost = $this->closedDeals($filters, $context, 'lost');
        $wonCount = (clone $won)->count();
        $lostCount = (clone $lost)->count();
        $closedCount = $wonCount + $lostCount;
        $winRate = $this->percentage($wonCount, $closedCount);
        $lossRate = $this->percentage($lostCount, $closedCount);
        $wonRevenue = $this->sumDealRevenue(clone $won, $context['currency']);
        $averageDealSize = $wonCount === 0 ? Money::of(0) : Money::divide($wonRevenue, (string) $wonCount);
        $salesCycle = $this->salesCycleDays(clone $won);

        $open = Deal::query()->where('status', 'open')
            ->where('forecast_category', '!=', 'omitted')
            ->whereBetween('expected_close_date', [$context['start']->toDateString(), $context['end']->toDateString()]);
        $this->applyDealFilters($open, $filters);
        $openCount = (clone $open)->count();
        $openRevenue = $this->sumDealRevenue(clone $open, $context['currency']);
        $averageOpenValue = $openCount === 0 ? Money::of(0) : Money::divide($openRevenue, (string) $openCount);
        $velocity = $salesCycle === Money::of(0)
            ? Money::of(0)
            : Money::divide(
                Money::multiply(
                    Money::multiply((string) $openCount, $averageOpenValue),
                    Money::divide($winRate, '100'),
                ),
                $salesCycle,
            );

        $dealsCreated = Deal::query()->whereBetween('created_at', [$context['start'], $context['end']]);
        $this->applyDealFilters($dealsCreated, $filters);
        $dealsCreatedCount = (clone $dealsCreated)->count();
        $leads = Lead::query()->whereBetween('created_at', [$context['start'], $context['end']]);
        $this->applyLeadFilters($leads, $filters);
        $leadCount = (clone $leads)->count();
        $convertedLeadCount = (clone $leads)->whereNotNull('converted_at')->where('converted_at', '<=', $context['end'])->count();

        return [
            'period' => ['start' => $context['start']->toDateString(), 'end' => $context['end']->toDateString()],
            'currency' => $this->currencyPayload($context['currency']),
            'filters' => $context['filters'],
            'metrics' => [
                'win_rate' => $winRate,
                'loss_rate' => $lossRate,
                'average_deal_size' => $averageDealSize,
                'sales_cycle' => $salesCycle,
                'pipeline_velocity' => $velocity,
                'conversion_rate' => $this->percentage($wonCount, $leadCount),
                'lead_to_opportunity' => $this->percentage($convertedLeadCount, $leadCount),
                'opportunity_to_sale' => $this->percentage($wonCount, $dealsCreatedCount),
            ],
            'counts' => [
                'leads' => $leadCount,
                'converted_leads' => $convertedLeadCount,
                'opportunities_created' => $dealsCreatedCount,
                'open_opportunities' => $openCount,
                'won_opportunities' => $wonCount,
                'lost_opportunities' => $lostCount,
            ],
            'revenue' => [
                'total' => $wonRevenue,
                'by_owner' => $this->revenueByDealDimension('owner', $filters, $context),
                'by_product' => $this->revenueByProduct($filters, $context),
                'by_industry' => $this->revenueByDealDimension('industry', $filters, $context),
                'by_territory' => $this->revenueByDealDimension('territory', $filters, $context),
                'by_source' => $this->revenueByDealDimension('source', $filters, $context),
            ],
            'definitions' => [
                'conversion_rate' => 'won opportunities / leads created',
                'lead_to_opportunity' => 'converted leads / leads created',
                'opportunity_to_sale' => 'won opportunities / opportunities created',
                'pipeline_velocity' => 'open opportunities × average open value × win rate / average sales cycle days',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{start: CarbonImmutable, end: CarbonImmutable, currency: Currency, filters: array<string, mixed>}
     */
    private function context(array $filters): array
    {
        $start = CarbonImmutable::parse($filters['starts_at'] ?? now()->startOfMonth())->startOfDay();
        $end = CarbonImmutable::parse($filters['ends_at'] ?? now()->endOfMonth())->endOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['ends_at' => 'The analytics end must not be before its start.']);
        }
        $currency = isset($filters['currency_id'])
            ? Currency::query()->where('active', true)->findOrFail($filters['currency_id'])
            : Currency::query()->where('is_base', true)->where('active', true)->first();
        if (! $currency instanceof Currency) {
            throw ValidationException::withMessages(['currency_id' => 'Configure an active base currency before requesting sales analytics.']);
        }

        return [
            'start' => $start,
            'end' => $end,
            'currency' => $currency,
            'filters' => collect([
                'pipeline_id' => $filters['pipeline_id'] ?? null,
                'owner_id' => $filters['owner_id'] ?? null,
                'team_id' => $filters['team_id'] ?? null,
                'branch_id' => $filters['branch_id'] ?? null,
                'territory_id' => $filters['territory_id'] ?? null,
                'industry' => $filters['industry'] ?? null,
                'product_id' => $filters['product_id'] ?? null,
                'source' => $filters['source'] ?? null,
            ])->map(fn ($value) => is_numeric($value) ? (int) $value : $value)->all(),
        ];
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $context */
    private function closedDeals(array $filters, array $context, string $status): Builder
    {
        $query = Deal::query()->where('status', $status)->whereBetween('closed_at', [$context['start'], $context['end']]);
        $this->applyDealFilters($query, $filters);

        return $query;
    }

    private function sumDealRevenue(Builder $query, Currency $target): string
    {
        $rows = $query->selectRaw('deals.currency as currency_code, SUM(deals.value) as aggregate')
            ->groupBy('deals.currency')
            ->get();

        return $this->convertCodeRows($rows, $target);
    }

    private function salesCycleDays(Builder $won): string
    {
        $seconds = Money::of(0);
        $count = 0;
        foreach ($won->select(['deals.id', 'deals.created_at', 'deals.closed_at'])->cursor() as $deal) {
            if ($deal->created_at === null || $deal->closed_at === null) {
                continue;
            }
            $seconds = Money::add(
                $seconds,
                (string) CarbonImmutable::parse($deal->created_at)->diffInSeconds(CarbonImmutable::parse($deal->closed_at)),
            );
            $count++;
        }
        if ($count === 0) {
            return Money::of(0);
        }

        return Money::divide(Money::divide((string) $seconds, (string) $count), '86400');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     * @return array<int, array{id: int|string|null, name: string, value: string}>
     */
    private function revenueByDealDimension(string $dimension, array $filters, array $context): array
    {
        $query = $this->closedDeals($filters, $context, 'won');
        $groupColumn = match ($dimension) {
            'owner' => 'deals.owner_id',
            'territory' => 'deals.territory_id',
            'industry' => 'organizations.industry',
            'source' => 'lead_sources.source',
            default => throw new \LogicException('Unsupported revenue dimension.'),
        };
        if ($dimension === 'industry') {
            $query->leftJoin('organizations', function ($join): void {
                $join->on('organizations.id', '=', 'deals.organization_id')
                    ->on('organizations.tenant_id', '=', 'deals.tenant_id')
                    ->whereNull('organizations.deleted_at');
            });
        } elseif ($dimension === 'source') {
            $tenantId = $this->tenantContext->requireId();
            $leadSources = $this->database->table('leads')
                ->select(['tenant_id', 'contact_id'])
                ->selectRaw('MIN(source) as source')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereNotNull('contact_id')
                ->groupBy(['tenant_id', 'contact_id']);
            $query->leftJoinSub($leadSources, 'lead_sources', function ($join): void {
                $join->on('lead_sources.tenant_id', '=', 'deals.tenant_id')
                    ->on('lead_sources.contact_id', '=', 'deals.contact_id');
            });
        }

        $rows = $query->selectRaw("{$groupColumn} as dimension_id, deals.currency as currency_code, SUM(deals.value) as aggregate")
            ->groupBy([$groupColumn, 'deals.currency'])
            ->get();

        return $this->dimensionRows($rows, $context['currency'], $dimension);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     * @return array<int, array{id: int|string|null, name: string, value: string}>
     */
    private function revenueByProduct(array $filters, array $context): array
    {
        $query = QuoteItem::query()
            ->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
            ->leftJoin('deals', function ($join): void {
                $join->on('deals.id', '=', 'quotes.deal_id')
                    ->on('deals.tenant_id', '=', 'quotes.tenant_id')
                    ->whereNull('deals.deleted_at');
            })
            ->whereNull('quotes.deleted_at')
            ->where('quotes.status', 'accepted')
            ->whereBetween('quotes.accepted_at', [$context['start'], $context['end']])
            ->whereNotNull('quote_items.product_id');
        $this->applyQuoteItemFilters($query, $filters);

        $rows = $query->selectRaw('quote_items.product_id as dimension_id, quotes.currency_id, SUM(quote_items.total) as aggregate')
            ->groupBy(['quote_items.product_id', 'quotes.currency_id'])
            ->get();

        return $this->dimensionCurrencyIdRows($rows, $context['currency'], 'product');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{id: int|string|null, name: string, value: string}>
     */
    private function dimensionRows(Collection $rows, Currency $currency, string $dimension): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $id = $row->dimension_id === null || $row->dimension_id === '' ? 'unknown' : (string) $row->dimension_id;
            $converted = $this->convertCodeRows(collect([$row]), $currency);
            $totals[$id] = Money::add($totals[$id] ?? Money::of(0), $converted);
        }

        return $this->labelAndSort($totals, $dimension);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{id: int|string|null, name: string, value: string}>
     */
    private function dimensionCurrencyIdRows(Collection $rows, Currency $currency, string $dimension): array
    {
        $currencyIds = $rows->pluck('currency_id')->map(fn ($id) => (int) $id)->unique()->values();
        $sourceCurrencies = Currency::query()->whereIn('id', $currencyIds)->get()->keyBy(fn (Currency $item) => (int) $item->id);
        $totals = [];
        foreach ($rows as $row) {
            $source = $sourceCurrencies->get((int) $row->currency_id);
            if (! $source instanceof Currency) {
                throw ValidationException::withMessages(['currency' => 'A quote uses a currency that is not configured for this tenant.']);
            }
            $id = (string) $row->dimension_id;
            $converted = $this->currencies->convert((string) $row->aggregate, $source, $currency);
            $totals[$id] = Money::add($totals[$id] ?? Money::of(0), $converted);
        }

        return $this->labelAndSort($totals, $dimension);
    }

    /**
     * @param  array<string, string>  $totals
     * @return array<int, array{id: int|string|null, name: string, value: string}>
     */
    private function labelAndSort(array $totals, string $dimension): array
    {
        $ids = collect(array_keys($totals))->reject(fn ($id) => $id === 'unknown');
        $labels = match ($dimension) {
            'owner' => User::query()->whereIn('id', $ids->map(fn ($id) => (int) $id))->pluck('name', 'id'),
            'territory' => Territory::query()->whereIn('id', $ids->map(fn ($id) => (int) $id))->pluck('name', 'id'),
            'product' => Product::query()->whereIn('id', $ids->map(fn ($id) => (int) $id))->pluck('name', 'id'),
            default => collect(),
        };
        $result = [];
        foreach ($totals as $id => $value) {
            $isUnknown = $id === 'unknown';
            $name = match ($dimension) {
                'owner', 'territory', 'product' => $isUnknown ? 'Unknown' : (string) ($labels->get((int) $id) ?? 'Unknown'),
                default => $isUnknown ? 'Unknown' : $id,
            };
            $result[] = ['id' => $isUnknown ? null : (is_numeric($id) ? (int) $id : $id), 'name' => $name, 'value' => $value];
        }
        usort($result, fn (array $left, array $right) => Money::compare($right['value'], $left['value']));

        return $result;
    }

    /** @param Collection<int, object> $rows */
    private function convertCodeRows(Collection $rows, Currency $target): string
    {
        $codes = $rows->pluck('currency_code')->filter()->map(fn ($code) => strtoupper((string) $code))->unique()->values();
        $sources = Currency::query()->whereIn('code', $codes)->get()->keyBy(fn (Currency $currency) => strtoupper($currency->code));
        $total = Money::of(0);
        foreach ($rows as $row) {
            $source = $sources->get(strtoupper((string) $row->currency_code));
            if (! $source instanceof Currency) {
                throw ValidationException::withMessages(['currency' => 'A deal uses a currency that is not configured for this tenant.']);
            }
            $total = Money::add($total, $this->currencies->convert((string) $row->aggregate, $source, $target));
        }

        return $total;
    }

    /** @param array<string, mixed> $filters */
    private function applyDealFilters(Builder $query, array $filters): void
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
        if (filled($filters['industry'] ?? null)) {
            $industry = mb_strtolower(trim((string) $filters['industry']));
            $query->whereHas('organization', fn (Builder $query) => $query->whereRaw('LOWER(industry) = ?', [$industry]));
        }
        if (isset($filters['product_id'])) {
            $query->whereExists(function ($query) use ($filters): void {
                $query->selectRaw('1')->from('quotes')
                    ->join('quote_items', 'quote_items.quote_id', '=', 'quotes.id')
                    ->whereColumn('quotes.deal_id', 'deals.id')
                    ->whereNull('quotes.deleted_at')
                    ->where('quote_items.product_id', $filters['product_id']);
            });
        }
        if (filled($filters['source'] ?? null)) {
            $source = mb_strtolower(trim((string) $filters['source']));
            $query->whereExists(function ($query) use ($source): void {
                $query->selectRaw('1')->from('leads')
                    ->whereColumn('leads.tenant_id', 'deals.tenant_id')
                    ->whereColumn('leads.contact_id', 'deals.contact_id')
                    ->whereNull('leads.deleted_at')
                    ->whereRaw('LOWER(leads.source) = ?', [$source]);
            });
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyLeadFilters(Builder $query, array $filters): void
    {
        if (isset($filters['owner_id'])) {
            $query->where('leads.owner_id', $filters['owner_id']);
        }
        if (isset($filters['territory_id'])) {
            $query->where('leads.territory_id', $filters['territory_id']);
        }
        if (filled($filters['source'] ?? null)) {
            $query->whereRaw('LOWER(leads.source) = ?', [mb_strtolower(trim((string) $filters['source']))]);
        }
        if (isset($filters['team_id'])) {
            $query->whereIn('leads.owner_id', function ($query) use ($filters): void {
                $query->select('user_id')->from('sales_team_members')
                    ->where('sales_team_id', $filters['team_id'])->where('active', true);
            });
        }
        if (isset($filters['branch_id'])) {
            $query->whereIn('leads.owner_id', function ($query) use ($filters): void {
                $query->select('user_id')->from('branch_members')
                    ->where('branch_id', $filters['branch_id'])->where('active', true);
            });
        }
        if (filled($filters['industry'] ?? null)) {
            $industry = mb_strtolower(trim((string) $filters['industry']));
            $query->whereHas('organization', fn (Builder $query) => $query->whereRaw('LOWER(industry) = ?', [$industry]));
        }
        if (isset($filters['product_id'])) {
            $productId = $filters['product_id'];
            $query->whereHas('contact.deals', function (Builder $query) use ($productId): void {
                $query->whereExists(function ($query) use ($productId): void {
                    $query->selectRaw('1')->from('quotes')
                        ->join('quote_items', 'quote_items.quote_id', '=', 'quotes.id')
                        ->whereColumn('quotes.deal_id', 'deals.id')
                        ->where('quote_items.product_id', $productId);
                });
            });
        }
    }

    /** @param Builder<QuoteItem> $query @param array<string, mixed> $filters */
    private function applyQuoteItemFilters(Builder $query, array $filters): void
    {
        if (isset($filters['owner_id'])) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->where('deals.owner_id', $filters['owner_id'])->orWhere(function (Builder $query) use ($filters): void {
                    $query->whereNull('deals.id')->where('quotes.owner_id', $filters['owner_id']);
                });
            });
        }
        foreach ([
            'pipeline_id' => 'deals.pipeline_id',
            'team_id' => 'deals.sales_team_id',
            'branch_id' => 'deals.branch_id',
            'territory_id' => 'deals.territory_id',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['product_id'])) {
            $query->where('quote_items.product_id', $filters['product_id']);
        }
        if (filled($filters['industry'] ?? null)) {
            $query->join('organizations as quote_organizations', function ($join): void {
                $join->on('quote_organizations.id', '=', 'quotes.organization_id')
                    ->on('quote_organizations.tenant_id', '=', 'quotes.tenant_id')
                    ->whereNull('quote_organizations.deleted_at');
            })->whereRaw('LOWER(quote_organizations.industry) = ?', [mb_strtolower(trim((string) $filters['industry']))]);
        }
        if (filled($filters['source'] ?? null)) {
            $source = mb_strtolower(trim((string) $filters['source']));
            $query->whereExists(function ($query) use ($source): void {
                $query->selectRaw('1')->from('leads')
                    ->whereColumn('leads.tenant_id', 'quotes.tenant_id')
                    ->whereColumn('leads.contact_id', 'deals.contact_id')
                    ->whereNull('leads.deleted_at')
                    ->whereRaw('LOWER(leads.source) = ?', [$source]);
            });
        }
    }

    private function percentage(int $numerator, int $denominator): string
    {
        return $denominator === 0
            ? Money::of(0)
            : Money::multiply(Money::divide((string) $numerator, (string) $denominator), '100');
    }

    /** @return array{id: int, code: string, symbol: string|null, decimal_places: int} */
    private function currencyPayload(Currency $currency): array
    {
        return [
            'id' => (int) $currency->id,
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'decimal_places' => (int) $currency->decimal_places,
        ];
    }
}
