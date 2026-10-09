<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadScoringService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateLeadScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $leadId,
        public readonly ?string $eventType = null,
        public readonly ?string $eventKey = null,
        public readonly array $metadata = [],
    ) {}

    public function handle(LeadScoringService $scoring, TenantContext $context): void
    {
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            $lead = Lead::findOrFail($this->leadId);
            if ($this->eventType !== null && $this->eventKey !== null) {
                $scoring->recordEvent($lead, $this->eventType, $this->eventKey, $this->metadata);

                return;
            }
            $scoring->recalculate($lead);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
