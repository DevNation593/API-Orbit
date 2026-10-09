<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ApprovalEngine;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class EscalateDueApprovalsCommand extends Command
{
    protected $signature = 'approvals:escalate-due {--limit=100}';

    protected $description = 'Escalate overdue approval steps for every active tenant.';

    public function handle(ApprovalEngine $engine): int
    {
        $context = app(TenantContext::class);
        $total = 0;
        Tenant::query()->where('status', 'active')->select('id')->chunkById(100, function ($tenants) use ($context, $engine, &$total): void {
            foreach ($tenants as $tenant) {
                try {
                    $context->set((int) $tenant->id);
                    $total += $engine->escalateDue(max(1, min((int) $this->option('limit'), 1000)));
                } finally {
                    $context->clear();
                }
            }
        });
        $this->info("Escalated {$total} approval request(s).");

        return self::SUCCESS;
    }
}
