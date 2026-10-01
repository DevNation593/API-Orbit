<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\BundleRule;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\DiscountRule;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDependency;
use App\Models\ProductVariant;
use App\Models\Tax;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class QuoteCalculationService
{
    public function __construct(
        private readonly CatalogPricingService $pricing,
        private readonly CurrencyConversionService $currencies,
        private readonly RuleConditionEvaluator $conditions,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{items: array<int, array<string, mixed>>, discounts: array<int, array<string, mixed>>, totals: array<string, string>, warnings: array<int, string>}
     */
    public function calculate(array $data): array
    {
        $currency = Currency::query()->where('active', true)->findOrFail($data['currency_id']);
        $priceList = filled($data['price_list_id'] ?? null) ? PriceList::query()->findOrFail($data['price_list_id']) : null;
        $context = $this->context($data, $currency);
        $lines = [];
        $subtotal = '0';
        $itemDiscountTotal = '0';

        foreach (array_values($data['items']) as $position => $input) {
            $quantity = Money::of((string) $input['quantity']);
            $product = filled($input['product_id'] ?? null)
                ? Product::query()->with(['currency', 'taxes.currency', 'bundle.items.product.currency', 'bundle.items.variant'])->findOrFail($input['product_id'])
                : null;
            $variant = filled($input['product_variant_id'] ?? null) ? ProductVariant::query()->findOrFail($input['product_variant_id']) : null;
            $pricing = $product === null
                ? ['unit_price' => Money::of((string) $input['unit_price']), 'source' => 'manual', 'applied_rules' => []]
                : (array_key_exists('unit_price', $input)
                    ? ['unit_price' => Money::of((string) $input['unit_price']), 'source' => 'manual', 'applied_rules' => []]
                    : $this->pricing->unitPrice($product, $variant, $currency, $priceList, $quantity, $context));
            $gross = Money::multiply($quantity, $pricing['unit_price']);
            $discountModels = Discount::query()->with('currency')->whereIn('id', $input['discount_ids'] ?? [])->get();
            [$discounts, $itemDiscount] = $this->applyDiscounts($gross, $discountModels, [
                ...$context, 'product' => $product?->attributesToArray(), 'variant' => $variant?->attributesToArray(),
                'quantity' => $quantity, 'line_subtotal' => $gross,
            ], $currency);
            $net = Money::subtract($gross, $itemDiscount);
            $taxIds = array_key_exists('tax_ids', $input)
                ? array_values(array_unique(array_map('intval', $input['tax_ids'])))
                : ($product?->taxes?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? []);
            $taxes = Tax::query()->with('currency')->whereIn('id', $taxIds)->where('active', true)->orderBy('priority')->orderBy('id')->get();
            $taxable = (bool) ($input['taxable'] ?? $product?->taxable ?? true);

            $lines[] = [
                'position' => $position, 'product_id' => $product?->id, 'product_variant_id' => $variant?->id,
                'item_type' => $product?->type ?? 'service', 'sku' => $variant?->sku ?? $product?->sku,
                'name' => $input['name'] ?? $variant?->name ?? $product?->name,
                'description' => $input['description'] ?? $product?->description,
                'unit_of_measure' => $product?->unit_of_measure ?? 'unit', 'quantity' => $quantity,
                'unit_price' => $pricing['unit_price'], 'subtotal' => $gross, 'item_discount_total' => $itemDiscount,
                'net_before_quote_discount' => $net, 'taxable' => $taxable, 'tax_models' => $taxes,
                'discounts' => $discounts, 'metadata' => array_replace($input['metadata'] ?? [], [
                    'price_source' => $pricing['source'], 'pricing_rules' => $pricing['applied_rules'],
                ]),
            ];
            $subtotal = Money::add($subtotal, $gross);
            $itemDiscountTotal = Money::add($itemDiscountTotal, $itemDiscount);
        }

        $warnings = $this->validateConfiguration($lines, $context);
        $netBeforeQuote = Money::subtract($subtotal, $itemDiscountTotal);
        $quoteDiscountModels = $this->discountCodes($data['discount_codes'] ?? []);
        [$quoteDiscounts, $catalogQuoteDiscount] = $this->applyDiscounts($netBeforeQuote, $quoteDiscountModels, [
            ...$context, 'subtotal' => $subtotal, 'net_subtotal' => $netBeforeQuote,
        ], $currency);
        [$ruleDiscounts, $ruleQuoteDiscount] = $this->automaticDiscounts(
            Money::subtract($netBeforeQuote, $catalogQuoteDiscount),
            [...$context, 'subtotal' => $subtotal, 'net_subtotal' => $netBeforeQuote],
            $currency,
        );
        $quoteDiscounts = [...$quoteDiscounts, ...$ruleDiscounts];
        $quoteDiscountTotal = Money::add($catalogQuoteDiscount, $ruleQuoteDiscount);
        $quoteDiscountTotal = Money::min($quoteDiscountTotal, $netBeforeQuote);

        $taxTotal = '0';
        $grandTotal = '0';
        $remainingDiscount = $quoteDiscountTotal;
        $remainingBase = $netBeforeQuote;
        $lastDiscountable = $this->lastPositiveLine($lines);
        foreach ($lines as $index => &$line) {
            $lineNet = $line['net_before_quote_discount'];
            if (Money::compare($lineNet, '0') <= 0 || Money::compare($quoteDiscountTotal, '0') === 0) {
                $share = '0';
            } elseif ($index === $lastDiscountable || Money::compare($remainingBase, '0') === 0) {
                $share = $remainingDiscount;
            } else {
                $share = Money::multiply($quoteDiscountTotal, Money::divide($lineNet, $netBeforeQuote));
                $share = Money::min($share, $remainingDiscount);
            }
            $remainingDiscount = Money::subtract($remainingDiscount, $share);
            $remainingBase = Money::subtract($remainingBase, $lineNet);
            $taxableAmount = Money::max(Money::subtract($lineNet, $share), '0');
            [$taxes, $lineTax, $exclusiveTax] = $line['taxable']
                ? $this->taxes($taxableAmount, $line['quantity'], $line['tax_models'], $currency)
                : [[], '0', '0'];
            $line['quote_discount_share'] = $share;
            $line['discount_total'] = Money::add($line['item_discount_total'], $share);
            $line['taxes'] = $taxes;
            $line['tax_total'] = $lineTax;
            $line['total'] = Money::add($taxableAmount, $exclusiveTax);
            unset($line['tax_models'], $line['item_discount_total'], $line['net_before_quote_discount']);
            $taxTotal = Money::add($taxTotal, $lineTax);
            $grandTotal = Money::add($grandTotal, $line['total']);
        }
        unset($line);

        return [
            'items' => $lines,
            'discounts' => $quoteDiscounts,
            'totals' => [
                'subtotal' => Money::of($subtotal),
                'discount_total' => Money::add($itemDiscountTotal, $quoteDiscountTotal),
                'tax_total' => Money::of($taxTotal),
                'grand_total' => Money::of($grandTotal),
            ],
            'warnings' => $warnings,
        ];
    }

    /** @param Collection<int, Discount> $discounts
     * @param  array<string, mixed>  $context
     * @return array{array<int, array<string, mixed>>, string}
     */
    private function applyDiscounts(string $base, Collection $discounts, array $context, Currency $currency): array
    {
        $remaining = $base;
        $total = '0';
        $applied = [];
        foreach ($discounts as $discount) {
            if (! $this->discountAvailable($discount, $base, $context)) {
                throw ValidationException::withMessages(['discount_codes' => "Discount {$discount->code} is not currently applicable."]);
            }
            if ($discount->type === 'fixed' && $discount->currency === null) {
                throw ValidationException::withMessages(['discount_codes' => "Fixed discount {$discount->code} has no currency."]);
            }
            $amount = $discount->type === 'percentage'
                ? Money::percentage($remaining, (string) $discount->value)
                : $this->currencies->convert((string) $discount->value, $discount->currency, $currency);
            if ($discount->maximum_discount !== null) {
                $maximum = $discount->type === 'fixed' && $discount->currency !== null
                    ? $this->currencies->convert((string) $discount->maximum_discount, $discount->currency, $currency)
                    : (string) $discount->maximum_discount;
                $amount = Money::min($amount, $maximum);
            }
            $amount = Money::min(Money::max($amount, '0'), $remaining);
            $remaining = Money::subtract($remaining, $amount);
            $total = Money::add($total, $amount);
            $applied[] = [
                'discount_id' => (int) $discount->id, 'discount_rule_id' => null, 'code' => $discount->code,
                'name' => $discount->name, 'type' => $discount->type, 'value' => (string) $discount->value,
                'amount' => $amount, 'reason' => null,
            ];
        }

        return [$applied, $total];
    }

    /** @param array<string, mixed> $context
     * @return array{array<int, array<string, mixed>>, string}
     */
    private function automaticDiscounts(string $base, array $context, Currency $currency): array
    {
        $rules = DiscountRule::query()->with('currency')->where('active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('priority')->orderBy('id')->get();
        $remaining = $base;
        $total = '0';
        $applied = [];
        foreach ($rules as $rule) {
            if (! $this->conditions->matches($rule->conditions ?? [], $context, $rule->match_type, ['event' => $context])) {
                continue;
            }
            $amount = $rule->type === 'percentage'
                ? Money::percentage($remaining, (string) $rule->value)
                : ($rule->currency === null ? Money::of((string) $rule->value) : $this->currencies->convert((string) $rule->value, $rule->currency, $currency));
            if ($rule->maximum_discount !== null) {
                $maximum = $rule->currency === null ? (string) $rule->maximum_discount : $this->currencies->convert((string) $rule->maximum_discount, $rule->currency, $currency);
                $amount = Money::min($amount, $maximum);
            }
            $amount = Money::min(Money::max($amount, '0'), $remaining);
            $remaining = Money::subtract($remaining, $amount);
            $total = Money::add($total, $amount);
            $applied[] = [
                'discount_id' => $rule->discount_id, 'discount_rule_id' => (int) $rule->id, 'code' => null,
                'name' => $rule->name, 'type' => $rule->type, 'value' => (string) $rule->value,
                'amount' => $amount, 'reason' => 'Automatic CPQ rule',
            ];
            if (! $rule->cumulative) {
                break;
            }
        }

        return [$applied, $total];
    }

    /** @param Collection<int, Tax> $taxes
     * @return array{array<int, array<string, mixed>>, string, string}
     */
    private function taxes(string $base, string $quantity, Collection $taxes, Currency $currency): array
    {
        $all = [];
        $total = '0';
        $exclusive = '0';
        $compoundBase = $base;
        foreach ($taxes as $position => $tax) {
            $taxableAmount = $compoundBase;
            if ($tax->calculation === 'fixed') {
                if ($tax->currency === null) {
                    throw ValidationException::withMessages(['taxes' => "Fixed tax {$tax->code} has no currency."]);
                }
                $amount = $this->currencies->convert(Money::multiply((string) $tax->rate, $quantity), $tax->currency, $currency);
            } elseif ($tax->inclusive) {
                $divisor = Money::add('1', Money::divide((string) $tax->rate, '100'));
                $amount = Money::subtract($compoundBase, Money::divide($compoundBase, $divisor));
            } else {
                $amount = Money::percentage($compoundBase, (string) $tax->rate);
            }
            $amount = Money::max($amount, '0');
            $total = Money::add($total, $amount);
            if (! $tax->inclusive) {
                $exclusive = Money::add($exclusive, $amount);
            }
            if ($tax->compound) {
                $compoundBase = Money::add($compoundBase, $amount);
            }
            $all[] = [
                'tax_id' => (int) $tax->id, 'code' => $tax->code, 'name' => $tax->name,
                'calculation' => $tax->calculation, 'rate' => (string) $tax->rate,
                'taxable_amount' => $taxableAmount, 'amount' => $amount, 'inclusive' => (bool) $tax->inclusive,
                'compound' => (bool) $tax->compound, 'position' => $position,
            ];
        }

        return [$all, $total, $exclusive];
    }

    /** @param array<int, array<string, mixed>> $lines
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function validateConfiguration(array $lines, array $context): array
    {
        $quantities = [];
        foreach ($lines as $line) {
            if ($line['product_id'] !== null) {
                $id = (int) $line['product_id'];
                $quantities[$id] = Money::add($quantities[$id] ?? '0', $line['quantity']);
            }
        }
        $warnings = [];
        $dependencies = ProductDependency::query()->whereIn('product_id', array_keys($quantities))->where('active', true)->get();
        foreach ($dependencies as $dependency) {
            if (! $this->conditions->matches($dependency->conditions ?? [], $context, 'all', ['event' => $context])) {
                continue;
            }
            $relatedQuantity = $quantities[(int) $dependency->related_product_id] ?? '0';
            if ($dependency->relation === 'requires' && Money::compare($relatedQuantity, (string) $dependency->minimum_quantity) < 0) {
                throw ValidationException::withMessages(['items' => "Product {$dependency->product_id} requires product {$dependency->related_product_id} with minimum quantity {$dependency->minimum_quantity}."]);
            }
            if ($dependency->relation === 'excludes' && Money::compare($relatedQuantity, '0') > 0) {
                throw ValidationException::withMessages(['items' => "Products {$dependency->product_id} and {$dependency->related_product_id} cannot be quoted together."]);
            }
            if ($dependency->relation === 'recommends' && Money::compare($relatedQuantity, (string) $dependency->minimum_quantity) < 0) {
                $warnings[] = "Product {$dependency->product_id} recommends product {$dependency->related_product_id}.";
            }
        }
        foreach ($lines as $line) {
            if ($line['item_type'] !== 'bundle' || $line['product_id'] === null) {
                continue;
            }
            $bundle = Bundle::query()->where('product_id', $line['product_id'])->first();
            if ($bundle === null) {
                continue;
            }
            $rules = BundleRule::query()->where('bundle_id', $bundle->id)->where('active', true)->orderBy('priority')->get();
            foreach ($rules as $rule) {
                if (! $this->conditions->matches($rule->conditions ?? [], $context, $rule->match_type, ['event' => $context])) {
                    continue;
                }
                if ($rule->minimum_quantity !== null && Money::compare($line['quantity'], (string) $rule->minimum_quantity) < 0) {
                    throw ValidationException::withMessages(['items' => "Bundle {$bundle->name} requires a higher quantity."]);
                }
                if ($rule->maximum_quantity !== null && Money::compare($line['quantity'], (string) $rule->maximum_quantity) > 0) {
                    throw ValidationException::withMessages(['items' => "Bundle {$bundle->name} exceeds its maximum quantity."]);
                }
            }
        }

        return $warnings;
    }

    /** @param array<int, string> $codes
     * @return Collection<int, Discount>
     */
    private function discountCodes(array $codes): Collection
    {
        $normalized = array_values(array_unique(array_map(fn ($code): string => strtoupper(trim((string) $code)), $codes)));
        $discounts = Discount::query()->with('currency')->whereIn('code', $normalized)->get();
        if ($discounts->count() !== count($normalized)) {
            $found = $discounts->pluck('code')->all();
            $missing = array_values(array_diff($normalized, $found));
            throw ValidationException::withMessages(['discount_codes' => 'Unknown discount codes: '.implode(', ', $missing).'.']);
        }

        return $discounts;
    }

    /** @param array<string, mixed> $context */
    private function discountAvailable(Discount $discount, string $base, array $context): bool
    {
        return $discount->active
            && ($discount->starts_at === null || ! $discount->starts_at->isFuture())
            && ($discount->ends_at === null || ! $discount->ends_at->isPast())
            && ($discount->usage_limit === null || $discount->usage_count < $discount->usage_limit)
            && ($discount->minimum_subtotal === null || Money::compare($base, (string) $discount->minimum_subtotal) >= 0)
            && $this->conditions->matches($discount->conditions ?? [], $context, 'all', ['event' => $context]);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function lastPositiveLine(array $lines): ?int
    {
        $last = null;
        foreach ($lines as $index => $line) {
            if (Money::compare($line['net_before_quote_discount'], '0') > 0) {
                $last = $index;
            }
        }

        return $last;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function context(array $data, Currency $currency): array
    {
        return [
            'deal_id' => $data['deal_id'] ?? null, 'contact_id' => $data['contact_id'] ?? null,
            'organization_id' => $data['organization_id'] ?? null, 'owner_id' => $data['owner_id'] ?? null,
            'currency_id' => (int) $currency->id, 'currency_code' => $currency->code,
            'metadata' => $data['metadata'] ?? [],
        ];
    }
}
