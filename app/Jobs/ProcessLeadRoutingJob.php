<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadRoutingService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLeadRoutingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $leadId,
        public readonly string $eventId,
        public readonly bool $force = false,
    ) {}

    public function handle(LeadRoutingService $routing, TenantContext $context): void
    {
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            $routing->route(Lead::findOrFail($this->leadId), $this->eventId, $this->force);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
