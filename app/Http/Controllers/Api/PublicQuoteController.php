<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicQuoteDecisionRequest;
use App\Models\Quote;
use App\Services\QuoteService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicQuoteController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly FilesystemManager $storage,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, string $publicId): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'size:64']]);
        $quote = $this->quote($publicId);

        return $this->withinTenant($quote, function () use ($quote, $request): JsonResponse {
            $this->quotes->assertPublicToken($quote, (string) $request->query('token'));

            return ApiResponse::success($this->present($this->quotes->markViewed($quote)));
        });
    }

    public function accept(PublicQuoteDecisionRequest $request, string $publicId): JsonResponse
    {
        return $this->decision($request, $publicId, 'accept');
    }

    public function reject(PublicQuoteDecisionRequest $request, string $publicId): JsonResponse
    {
        return $this->decision($request, $publicId, 'reject');
    }

    public function pdf(Request $request, string $publicId): StreamedResponse
    {
        $request->validate(['token' => ['required', 'string', 'size:64']]);
        $quote = $this->quote($publicId);

        return $this->withinTenant($quote, function () use ($quote, $request): StreamedResponse {
            $this->quotes->assertPublicToken($quote, (string) $request->query('token'));
            if ($quote->pdfFile === null || ! $this->storage->disk($quote->pdfFile->disk)->exists($quote->pdfFile->path)) {
                throw ValidationException::withMessages(['pdf' => 'The quote PDF is not available.']);
            }

            return $this->storage->disk($quote->pdfFile->disk)->download($quote->pdfFile->path, $quote->pdfFile->filename, ['Content-Type' => 'application/pdf']);
        });
    }

    private function decision(PublicQuoteDecisionRequest $request, string $publicId, string $decision): JsonResponse
    {
        $quote = $this->quote($publicId);

        return $this->withinTenant($quote, function () use ($quote, $request, $decision): JsonResponse {
            $data = $request->validated();
            $this->quotes->assertPublicToken($quote, $data['token']);
            unset($data['token']);
            $result = $this->quotes->decide($quote, $decision, $data, $request->ip());

            return ApiResponse::success($this->present($result['quote']), ['replayed' => $result['replayed']]);
        });
    }

    private function quote(string $publicId): Quote
    {
        return Quote::query()->withoutGlobalScope('tenant')->with([
            'tenant:id,name', 'contact:id,first_name,last_name', 'organization:id,name',
            'currency:id,code,name,symbol,decimal_places', 'items.taxes', 'items.discounts', 'discounts', 'pdfFile',
        ])->where('public_id', $publicId)->whereIn('status', ['sent', 'viewed', 'accepted', 'rejected'])->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Quote $quote): array
    {
        return [
            'public_id' => $quote->public_id, 'number' => $quote->number, 'version' => $quote->version,
            'status' => $quote->status, 'title' => $quote->title, 'valid_until' => $quote->valid_until?->format('Y-m-d'),
            'seller' => ['name' => $quote->tenant->name],
            'customer' => ['name' => $quote->organization?->name ?? trim(($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? ''))],
            'currency' => $quote->currency->only(['code', 'name', 'symbol', 'decimal_places']),
            'items' => $quote->items->map(fn ($item): array => [
                'name' => $item->name, 'description' => $item->description, 'quantity' => $item->quantity,
                'unit_price' => $item->unit_price, 'subtotal' => $item->subtotal,
                'discount_total' => $item->discount_total, 'tax_total' => $item->tax_total, 'total' => $item->total,
                'taxes' => $item->taxes->map->only(['name', 'rate', 'amount', 'inclusive']),
            ]),
            'subtotal' => $quote->subtotal, 'discount_total' => $quote->discount_total,
            'tax_total' => $quote->tax_total, 'grand_total' => $quote->grand_total,
            'notes' => $quote->notes, 'terms' => $quote->terms, 'pdf_available' => $quote->pdf_file_id !== null,
            'sent_at' => $quote->sent_at?->toIso8601String(), 'viewed_at' => $quote->viewed_at?->toIso8601String(),
            'accepted_at' => $quote->accepted_at?->toIso8601String(), 'rejected_at' => $quote->rejected_at?->toIso8601String(),
        ];
    }

    private function withinTenant(Quote $quote, callable $callback): mixed
    {
        $previous = $this->context->id();
        try {
            $this->context->set((int) $quote->tenant_id);

            return $callback();
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
