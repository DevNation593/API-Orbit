<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSupportSlaJob;
use App\Models\SlaExecution;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class DispatchDueSupportSlaCommand extends Command
{
    protected $signature = 'support:dispatch-sla {--limit=1000 : Maximum jobs to enqueue (1-10000)}';

    protected $description = 'Dispatch overdue support SLA evaluations and recover pending alerts';

    public function handle(TenantContext $context): int
    {
        $remaining = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($remaining === false) {
            $this->error('The limit must be an integer between 1 and 10000.');

            return self::FAILURE;
        }
        $queued = 0;
        $previous = $context->id();
        $at = now()->toImmutable()->utc()->startOfSecond();
        try {
            foreach (Tenant::where('status', 'active')->select('id')->lazyById(200) as $tenant) {
                $context->set((int) $tenant->id);
                $due = SlaExecution::where(function ($query) use ($at): void {
                    $query->where(fn ($q) => $q->whereNull('first_response_at')->where('first_response_breached', false)->where('first_response_due_at', '<', $at))
                        ->orWhere(fn ($q) => $q->where('status', 'RUNNING')->where('resolution_breached', false)->where('resolution_due_at', '<', $at))
                        ->orWhereHas('escalations', fn ($q) => $q->where('status', 'pending')->where('next_attempt_at', '<=', $at)
                            ->where(fn ($lease) => $lease->whereNull('reserved_until')->orWhere('reserved_until', '<=', $at)));
                })->select('id');
                foreach ($due->lazyById(200) as $execution) {
                    ProcessSupportSlaJob::dispatch((int) $tenant->id, (int) $execution->id);
                    $queued++;
                    if (--$remaining === 0) {
                        break 2;
                    }
                }
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
        $this->info("Queued {$queued} support SLA job(s).");

        return self::SUCCESS;
    }
}
