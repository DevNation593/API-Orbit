<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMarketingJob;
use App\Models\Campaign;
use App\Models\JourneyEnrollment;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class DispatchDueMarketingCommand extends Command
{
    protected $signature = 'marketing:dispatch-due {--limit=1000 : Maximum jobs to enqueue}';

    protected $description = 'Dispatch due campaigns and journey nodes, preserving tenant context';

    public function handle(TenantContext $context): int
    {
        $remaining = max(1, min(10000, (int) $this->option('limit')));
        $queued = 0;
        $previous = $context->id();
        try {
            foreach (Tenant::where('status', 'active')->select('id')->lazyById(200) as $tenant) {
                $context->set((int) $tenant->id);
                $queries = [
                    'campaign' => Campaign::whereIn('status', ['scheduled', 'sending'])
                        ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now())),
                    'journey' => JourneyEnrollment::where('status', 'active')->where('next_run_at', '<=', now())
                        ->whereHas('journey', fn ($q) => $q->where('status', 'active')),
                ];
                foreach ($queries as $kind => $query) {
                    foreach ($query->orderBy('id')->limit($remaining)->pluck('id') as $id) {
                        ProcessMarketingJob::dispatch((int) $tenant->id, $kind, (int) $id);
                        $queued++;
                        $remaining--;
                        if ($remaining < 1) {
                            break 3;
                        }
                    }
                }
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
        $this->info("Queued {$queued} due marketing job(s).");

        return self::SUCCESS;
    }
}
