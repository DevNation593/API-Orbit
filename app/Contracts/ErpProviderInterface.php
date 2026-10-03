<?php

namespace App\Contracts;

use App\Models\Integration;
use App\Models\Product;
use App\Models\Quote;

interface ErpProviderInterface
{
    public function key(): string;

    /** @return array{external_id: string, response_status: int, request_summary: array<string, mixed>, response_summary: array<string, mixed>} */
    public function syncProduct(Integration $integration, Product $product, string $idempotencyKey): array;

    /** @return array{external_id: string, response_status: int, request_summary: array<string, mixed>, response_summary: array<string, mixed>} */
    public function syncAcceptedQuote(Integration $integration, Quote $quote, string $idempotencyKey): array;
}
