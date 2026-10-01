<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Services\QuotePdfService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class GenerateQuotePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $tenantId, public readonly int $quoteId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("quote-pdf:{$this->tenantId}:{$this->quoteId}"))->expireAfter(300)];
    }

    public function handle(QuotePdfService $pdf): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        try {
            $quote = Quote::query()->findOrFail($this->quoteId);
            $pdf->generate($quote);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
