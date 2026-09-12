<?php

namespace App\Jobs;

use App\Models\SlaEscalation;
use App\Models\SlaExecution;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Services\SlaEngine;
use App\Services\SlaEscalationService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessSupportSlaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $tenantId, public readonly int $executionId)
    {
        $this->onQueue('support');
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->executionId;
    }

    public function handle(TenantContext $context, SlaEngine $engine, SlaEscalationService $escalations): void
    {
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            if (! Tenant::whereKey($this->tenantId)->where('status', 'active')->exists()) {
                return;
            }
            DB::transaction(function () use ($engine): void {
                $ticketId = SlaExecution::whereKey($this->executionId)->value('ticket_id');
                if ($ticketId === null) {
                    return;
                }
                Ticket::whereKey($ticketId)->lockForUpdate()->firstOrFail();
                $execution = SlaExecution::whereKey($this->executionId)->lockForUpdate()->firstOrFail();
                $engine->evaluate($execution, now()->toImmutable()->utc()->startOfSecond());
            });
            foreach (SlaEscalation::where('execution_id', $this->executionId)->where('status', 'pending')->pluck('id') as $id) {
                $escalations->dispatch((int) $id);
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
