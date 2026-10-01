<?php

namespace App\Listeners;

use App\Events\QuoteAccepted;
use App\Models\Integration;
use App\Services\ErpSyncService;
use App\Support\TenantContext;

class QueueAcceptedQuoteToErp
{
    public function __construct(private readonly ErpSyncService $syncs) {}

    public function handle(QuoteAccepted $event): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set((int) $event->quote->tenant_id);
        try {
            if (Integration::query()->where('provider', 'vantex_erp')->where('status', 'active')->exists()) {
                $this->syncs->queue('quote', $event->quote);
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
