<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CatalogPricingService
{
    public function __construct(
        private readonly CurrencyConversionService $currencies,
        private readonly RuleConditionEvaluator $conditions,
    ) {}

    /** @param array<string, mixed> $context
     * @return array{unit_price: string, source: string, applied_rules: array<int, array<string, mixed>>}
     */
    public function unitPrice(Product $product, ?ProductVariant $variant, Currency $currency, ?PriceList $priceList, string $quantity, array $context = []): array
    {
        if (! $product->active) {
            throw ValidationException::withMessages(['product_id' => 'The selected product is inactive.']);
        }
        if ($variant !== null && ((int) $variant->product_id !== (int) $product->id || ! $variant->active)) {
            throw ValidationException::withMessages(['product_variant_id' => 'The selected variant is not active for this product.']);
        }
        if ((int) $currency->tenant_id !== (int) $product->tenant_id) {
            throw ValidationException::withMessages(['currency_id' => 'The quote currency does not belong to this tenant.']);
        }

        $sourceCurrency = $product->currency()->firstOrFail();
        $base = $this->basePrice($product, $variant, $currency, $priceList, $quantity);
        $price = $base['price'];
        $source = $base['source'];
        if ($source !== 'price_list') {
            $price = $this->currencies->convert($price, $sourceCurrency, $currency);
        }

        $subject = [
            'product' => ['id' => (int) $product->id, 'category_id' => $product->category_id, 'type' => $product->type, 'sku' => $product->sku],
            'variant' => $variant?->attributesToArray(), 'quantity' => $quantity,
            'currency' => ['id' => (int) $currency->id, 'code' => $currency->code],
            ...$context,
        ];
        $rules = PricingRule::query()->with('currency')->where('active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('priority')->orderBy('id')->get();
        $applied = [];
        foreach ($rules as $rule) {
            if (! $this->targets($rule, $product) || ! $this->conditions->matches($rule->conditions ?? [], $subject, $rule->match_type, ['event' => $subject])) {
                continue;
            }
            $value = (string) $rule->value;
            if ($rule->currency !== null && $rule->action_type !== 'percentage_adjustment') {
                $value = $this->currencies->convert($value, $rule->currency, $currency);
            }
            $price = match ($rule->action_type) {
                'set_price' => Money::of($value),
                'percentage_adjustment' => Money::add($price, Money::percentage($price, $value)),
                'fixed_adjustment' => Money::add($price, $value),
                default => $price,
            };
            $price = Money::max($price, '0');
            $applied[] = ['id' => (int) $rule->id, 'name' => $rule->name, 'action_type' => $rule->action_type, 'value' => (string) $rule->value];
        }

        return ['unit_price' => Money::of($price), 'source' => $source, 'applied_rules' => $applied];
    }

    /** @return array{price: string, source: string} */
    private function basePrice(Product $product, ?ProductVariant $variant, Currency $currency, ?PriceList $priceList, string $quantity): array
    {
        if ($priceList !== null) {
            if (! $priceList->active || (int) $priceList->currency_id !== (int) $currency->id
                || ($priceList->starts_at !== null && $priceList->starts_at->isFuture())
                || ($priceList->ends_at !== null && $priceList->ends_at->isPast())) {
                throw ValidationException::withMessages(['price_list_id' => 'The selected price list is not currently available for this currency.']);
            }
            $item = $this->priceListItem($priceList, $product, $variant, $quantity);
            if ($item !== null) {
                return ['price' => (string) $item->unit_price, 'source' => 'price_list'];
            }
        }

        if ($product->type === 'bundle') {
            $bundle = Bundle::query()->with(['items.product.currency', 'items.variant'])->where('product_id', $product->id)->where('active', true)->first();
            if ($bundle === null) {
                throw ValidationException::withMessages(['product_id' => 'The bundle product has no active bundle definition.']);
            }
            if ($bundle->pricing_method === 'fixed') {
                return ['price' => Money::of((string) ($bundle->fixed_price ?? $product->base_price)), 'source' => 'bundle_fixed'];
            }
            $total = '0';
            foreach ($bundle->items as $item) {
                $component = $item->price_override ?? Money::add((string) $item->product->base_price, (string) ($item->variant?->price_adjustment ?? '0'));
                $component = $this->currencies->convert((string) $component, $item->product->currency, $product->currency);
                $total = Money::add($total, Money::multiply($component, (string) $item->quantity));
            }

            return ['price' => $total, 'source' => 'bundle_components'];
        }

        return ['price' => Money::add((string) $product->base_price, (string) ($variant?->price_adjustment ?? '0')), 'source' => 'catalog'];
    }

    private function priceListItem(PriceList $priceList, Product $product, ?ProductVariant $variant, string $quantity): ?PriceListItem
    {
        $base = PriceListItem::query()->where('price_list_id', $priceList->id)->where('product_id', $product->id)
            ->where('active', true)->where('minimum_quantity', '<=', $quantity)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', CarbonImmutable::now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', CarbonImmutable::now()));
        if ($variant !== null) {
            $specific = (clone $base)->where('product_variant_id', $variant->id)->orderByDesc('minimum_quantity')->first();
            if ($specific !== null) {
                return $specific;
            }
        }

        return $base->whereNull('product_variant_id')->orderByDesc('minimum_quantity')->first();
    }

    private function targets(PricingRule $rule, Product $product): bool
    {
        return match ($rule->target_type) {
            'all' => true,
            'product' => (int) $rule->target_id === (int) $product->id,
            'category' => (int) $rule->target_id === (int) $product->category_id,
            default => false,
        };
    }
}
