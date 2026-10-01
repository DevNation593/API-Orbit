<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductDependency;
use App\Models\ProductVariant;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CatalogService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveCurrency(Currency $currency, array $data): Currency
    {
        return $this->database->transaction(function () use ($currency, $data): Currency {
            $exists = $currency->exists;
            $old = $exists ? $currency->getAttributes() : null;
            $willBeBase = array_key_exists('is_base', $data) ? (bool) $data['is_base'] : (bool) $currency->is_base;
            if (! $exists && ! Currency::query()->exists()) {
                $willBeBase = true;
            }
            if ($exists && $currency->is_base && ! $willBeBase) {
                throw ValidationException::withMessages(['is_base' => 'Promote another currency instead of demoting the current base currency.']);
            }
            if ($willBeBase) {
                if (array_key_exists('active', $data) && ! $data['active']) {
                    throw ValidationException::withMessages(['active' => 'The base currency must remain active.']);
                }
                if (array_key_exists('exchange_rate', $data) && bccomp((string) $data['exchange_rate'], '1', 10) !== 0) {
                    throw ValidationException::withMessages(['exchange_rate' => 'The base currency exchange rate must be 1.']);
                }
                Currency::query()->when($exists, fn ($query) => $query->where('id', '!=', $currency->id))
                    ->update(['is_base' => false]);
                $data['is_base'] = true;
                $data['exchange_rate'] = '1';
                $data['active'] = true;
            }
            $currency->fill($data)->save();
            $this->audit->record($exists ? 'currency_updated' : 'currency_created', $currency, oldValues: $old, newValues: $currency->getAttributes());

            return $currency->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveCategory(ProductCategory $category, array $data): ProductCategory
    {
        if ($category->exists && isset($data['parent_id'])) {
            $this->assertCategoryParent($category, $data['parent_id']);
        }
        $exists = $category->exists;
        $old = $exists ? $category->getAttributes() : null;
        $category->fill($data)->save();
        $this->audit->record($exists ? 'product_category_updated' : 'product_category_created', $category, oldValues: $old, newValues: $category->getAttributes());

        return $category->fresh(['parent:id,name']);
    }

    /** @param array<string, mixed> $data */
    public function saveProduct(Product $product, array $data, int $userId): Product
    {
        return $this->database->transaction(function () use ($product, $data, $userId): Product {
            $hasTaxIds = array_key_exists('tax_ids', $data);
            $taxIds = array_values(array_unique(array_map('intval', $data['tax_ids'] ?? [])));
            unset($data['tax_ids']);
            $exists = $product->exists;
            $old = $exists ? $this->productAudit($product) : null;
            if (! $exists) {
                $data['created_by'] = $userId;
            }
            $product->fill($data)->save();
            if ($hasTaxIds) {
                $product->taxes()->syncWithPivotValues($taxIds, ['tenant_id' => (int) $product->tenant_id]);
            }
            $this->audit->record($exists ? 'product_updated' : 'product_created', $product, oldValues: $old, newValues: $this->productAudit($product));

            return $product->fresh($this->productRelations());
        });
    }

    /** @param array<string, mixed> $data */
    public function saveVariant(Product $product, ProductVariant $variant, array $data): ProductVariant
    {
        if ($variant->exists && (int) $variant->product_id !== (int) $product->id) {
            throw ValidationException::withMessages(['variant' => 'The variant does not belong to this product.']);
        }
        $exists = $variant->exists;
        $old = $exists ? $variant->getAttributes() : null;
        $variant->product_id = $product->id;
        $variant->fill($data)->save();
        $this->audit->record($exists ? 'product_variant_updated' : 'product_variant_created', $variant, oldValues: $old, newValues: $variant->getAttributes());

        return $variant->fresh(['product:id,sku,name']);
    }

    /** @param array<string, mixed> $data */
    public function saveBundle(Bundle $bundle, array $data): Bundle
    {
        return $this->database->transaction(function () use ($bundle, $data): Bundle {
            $items = $data['items'] ?? null;
            unset($data['items']);
            $product = Product::query()->findOrFail($data['product_id'] ?? $bundle->product_id);
            if ($product->type !== 'bundle') {
                throw ValidationException::withMessages(['product_id' => 'The selected product must have type bundle.']);
            }
            $exists = $bundle->exists;
            $duplicate = Bundle::query()->where('product_id', $product->id);
            if ($exists) {
                $duplicate->whereKeyNot($bundle->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['product_id' => 'The selected product already has a bundle definition.']);
            }
            $pricingMethod = $data['pricing_method'] ?? $bundle->pricing_method;
            $fixedPrice = array_key_exists('fixed_price', $data) ? $data['fixed_price'] : $bundle->fixed_price;
            if ($pricingMethod === 'fixed' && $fixedPrice === null) {
                throw ValidationException::withMessages(['fixed_price' => 'A fixed-price bundle requires fixed_price.']);
            }
            $old = $exists ? $bundle->getAttributes() : null;
            $bundle->fill($data)->save();
            if (is_array($items)) {
                $bundle->items()->delete();
                foreach ($items as $position => $item) {
                    $item['position'] ??= $position;
                    $bundle->items()->create($item);
                }
            }
            $this->audit->record($exists ? 'bundle_updated' : 'bundle_created', $bundle, oldValues: $old, newValues: $bundle->getAttributes());

            return $bundle->fresh(['product.currency:id,code', 'items.product:id,sku,name,type,currency_id,base_price', 'items.variant:id,product_id,sku,name,price_adjustment']);
        });
    }

    /** @param array<string, mixed> $data */
    public function savePriceList(PriceList $priceList, array $data): PriceList
    {
        return $this->database->transaction(function () use ($priceList, $data): PriceList {
            $items = $data['items'] ?? null;
            unset($data['items']);
            $exists = $priceList->exists;
            $old = $exists ? $priceList->getAttributes() : null;
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->when($exists, fn ($query) => $query->where('id', '!=', $priceList->id))
                    ->update(['is_default' => false]);
            }
            $priceList->fill($data)->save();
            if (is_array($items)) {
                $priceList->items()->delete();
                foreach ($items as $item) {
                    $priceList->items()->create($item);
                }
            }
            $this->audit->record($exists ? 'price_list_updated' : 'price_list_created', $priceList, oldValues: $old, newValues: $priceList->getAttributes());

            return $priceList->fresh(['currency:id,code,name,symbol,decimal_places', 'items.product:id,sku,name', 'items.variant:id,product_id,sku,name']);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveDependency(ProductDependency $dependency, array $data): ProductDependency
    {
        $productId = (int) ($data['product_id'] ?? $dependency->product_id);
        $relatedProductId = (int) ($data['related_product_id'] ?? $dependency->related_product_id);
        $relation = $data['relation'] ?? $dependency->relation;
        if ($productId === $relatedProductId) {
            throw ValidationException::withMessages(['related_product_id' => 'A product cannot depend on itself.']);
        }
        $query = ProductDependency::query()->where('product_id', $productId)
            ->where('related_product_id', $relatedProductId)
            ->where('relation', $relation);
        if ($dependency->exists) {
            $query->where('id', '!=', $dependency->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['relation' => 'This product dependency already exists.']);
        }
        $exists = $dependency->exists;
        $old = $exists ? $dependency->getAttributes() : null;
        $dependency->fill($data)->save();
        $this->audit->record($exists ? 'product_dependency_updated' : 'product_dependency_created', $dependency, oldValues: $old, newValues: $dependency->getAttributes());

        return $dependency->fresh(['product:id,sku,name', 'relatedProduct:id,sku,name']);
    }

    /** @param array<string, mixed> $data */
    public function saveSimple(Model $model, array $data, string $eventPrefix): Model
    {
        $exists = $model->exists;
        $old = $exists ? $model->getAttributes() : null;
        $model->fill($data)->save();
        $this->audit->record($eventPrefix.($exists ? '_updated' : '_created'), $model, oldValues: $old, newValues: $model->getAttributes());

        return $model->fresh();
    }

    public function delete(Model $model, string $event): void
    {
        $this->database->transaction(function () use ($model, $event): void {
            $old = $model->getAttributes();
            $model->delete();
            $this->audit->record($event, $model, oldValues: $old);
        });
    }

    private function assertCategoryParent(ProductCategory $category, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ((int) $parentId === (int) $category->id) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
        }
        $cursor = ProductCategory::query()->find($parentId);
        $visited = [];
        while ($cursor !== null && ! isset($visited[$cursor->id])) {
            if ((int) $cursor->id === (int) $category->id) {
                throw ValidationException::withMessages(['parent_id' => 'The selected parent would create a category cycle.']);
            }
            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id === null ? null : ProductCategory::query()->find($cursor->parent_id);
        }
    }

    /** @return array<int, string> */
    private function productRelations(): array
    {
        return ['category:id,name', 'currency:id,code,name,symbol,decimal_places', 'taxes:id,code,name,calculation,rate,inclusive,compound', 'variants'];
    }

    /** @return array<string, mixed> */
    private function productAudit(Product $product): array
    {
        return [
            'sku' => $product->sku, 'name' => $product->name, 'type' => $product->type,
            'category_id' => $product->category_id, 'currency_id' => $product->currency_id,
            'base_price' => $product->base_price, 'active' => $product->active,
        ];
    }
}
