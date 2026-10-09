<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSequenceEnrollmentJob;
use App\Models\SequenceEnrollment;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class DispatchDueSequencesCommand extends Command
{
    protected $signature = 'sequences:dispatch-due {--limit=1000 : Maximum enrollments to enqueue}';

    protected $description = 'Queue due sequence enrollment steps without blocking workers';

    public function handle(TenantContext $context): int
    {
        $remaining = max(1, min(10000, (int) $this->option('limit')));
        $queued = 0;
        $previous = $context->id();
        try {
            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->pluck('id') as $tenantId) {
                if ($remaining < 1) {
                    break;
                }
                $context->set((int) $tenantId);
                $ids = SequenceEnrollment::query()->where('status', 'active')->whereNotNull('next_run_at')
                    ->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit($remaining)->pluck('id');
                foreach ($ids as $id) {
                    ProcessSequenceEnrollmentJob::dispatch((int) $tenantId, (int) $id)->onQueue('sequences');
                    $queued++;
                    $remaining--;
                }
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
        $this->info("Queued {$queued} due sequence enrollment(s).");

        return self::SUCCESS;
    }
}
