<?php

namespace App\Services\Erp;

use App\Contracts\ErpProviderInterface;
use App\Models\Integration;
use App\Models\Product;
use App\Models\Quote;
use App\Support\UrlSafety;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class VantexErpProvider implements ErpProviderInterface
{
    public function key(): string
    {
        return 'vantex_erp';
    }

    public function syncProduct(Integration $integration, Product $product, string $idempotencyKey): array
    {
        $product->loadMissing(['category:id,name', 'currency:id,code', 'variants', 'taxes:id,code,name,rate', 'bundle.items.product:id,sku', 'bundle.items.variant:id,sku']);
        $payload = [
            'crm_id' => (int) $product->id, 'external_id' => $product->erp_product_id, 'sku' => $product->sku,
            'name' => $product->name, 'description' => $product->description, 'type' => $product->type,
            'category' => $product->category?->name, 'currency' => $product->currency->code,
            'unit_of_measure' => $product->unit_of_measure, 'base_price' => (string) $product->base_price,
            'cost' => $product->cost, 'taxable' => (bool) $product->taxable, 'active' => (bool) $product->active,
            'variants' => $product->variants->map(fn ($variant): array => [
                'crm_id' => (int) $variant->id, 'external_id' => $variant->erp_product_id, 'sku' => $variant->sku,
                'name' => $variant->name, 'attributes' => $variant->attributes, 'price_adjustment' => (string) $variant->price_adjustment,
            ])->all(),
            'taxes' => $product->taxes->map->only(['code', 'name', 'rate'])->all(),
        ];

        return $this->post($integration, (string) data_get($integration->settings, 'products_path', '/api/v1/crm/products/upsert'), $payload, $idempotencyKey, [
            'entity' => 'product', 'crm_id' => (int) $product->id, 'sku' => $product->sku,
            'variants' => $product->variants->count(),
        ]);
    }

    public function syncAcceptedQuote(Integration $integration, Quote $quote, string $idempotencyKey): array
    {
        $quote->loadMissing(['currency:id,code', 'contact:id,first_name,last_name,email,phone', 'organization:id,name,legal_name,email,phone', 'items.taxes', 'items.discounts']);
        $payload = [
            'crm_id' => (int) $quote->id, 'external_id' => $quote->erp_document_id,
            'number' => $quote->number, 'version' => (int) $quote->version, 'status' => $quote->status,
            'currency' => $quote->currency->code, 'accepted_at' => $quote->accepted_at?->toIso8601String(),
            'customer' => [
                'contact_crm_id' => $quote->contact_id, 'organization_crm_id' => $quote->organization_id,
                'name' => $quote->organization?->name ?? trim(($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? '')),
                'email' => $quote->contact?->email ?? $quote->organization?->email,
                'phone' => $quote->contact?->phone ?? $quote->organization?->phone,
            ],
            'totals' => [
                'subtotal' => (string) $quote->subtotal, 'discount_total' => (string) $quote->discount_total,
                'tax_total' => (string) $quote->tax_total, 'grand_total' => (string) $quote->grand_total,
            ],
            'items' => $quote->items->map(fn ($item): array => [
                'crm_id' => (int) $item->id, 'product_crm_id' => $item->product_id, 'variant_crm_id' => $item->product_variant_id,
                'sku' => $item->sku, 'name' => $item->name, 'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price, 'discount_total' => (string) $item->discount_total,
                'tax_total' => (string) $item->tax_total, 'total' => (string) $item->total,
                'taxes' => $item->taxes->map->only(['code', 'name', 'rate', 'amount'])->all(),
                'discounts' => $item->discounts->map->only(['code', 'name', 'type', 'value', 'amount'])->all(),
            ])->all(),
        ];

        return $this->post($integration, (string) data_get($integration->settings, 'sales_orders_path', '/api/v1/crm/sales-orders'), $payload, $idempotencyKey, [
            'entity' => 'accepted_quote', 'crm_id' => (int) $quote->id, 'number' => $quote->number,
            'version' => (int) $quote->version, 'items' => $quote->items->count(),
        ]);
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>  $summary
     * @return array{external_id: string, response_status: int, request_summary: array<string, mixed>, response_summary: array<string, mixed>}
     */
    private function post(Integration $integration, string $path, array $payload, string $idempotencyKey, array $summary): array
    {
        $url = rtrim((string) data_get($integration->settings, 'base_url'), '/').'/'.ltrim($path, '/');
        if (! UrlSafety::isPublic($url)) {
            throw new RuntimeException('The configured ERP endpoint is not a public HTTP URL.');
        }
        $response = $this->request($integration)->withHeaders([
            'Idempotency-Key' => $idempotencyKey, 'X-Vantex-CRM-Tenant' => (string) $integration->tenant_id,
        ])->post($url, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('ERP synchronization returned HTTP '.$response->status().'.');
        }
        $externalId = data_get($response->json(), 'data.id', data_get($response->json(), 'id'));
        if (! is_string($externalId) && ! is_int($externalId)) {
            throw new RuntimeException('ERP synchronization response did not include an external id.');
        }

        return [
            'external_id' => (string) $externalId, 'response_status' => $response->status(),
            'request_summary' => $summary,
            'response_summary' => ['external_id' => (string) $externalId, 'status' => data_get($response->json(), 'data.status', data_get($response->json(), 'status'))],
        ];
    }

    private function request(Integration $integration): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])
            ->withToken((string) data_get($integration->credentials, 'api_token'));
    }
}
