<?php

namespace App\Console\Commands;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class SyncEmailAccountsCommand extends Command
{
    protected $signature = 'inbox:sync-email {--limit=500 : Maximum accounts to enqueue} {--messages=100 : Messages per account}';

    protected $description = 'Queue incremental inbound synchronization for active email accounts';

    public function handle(TenantContext $context): int
    {
        $remaining = max(1, min(5000, (int) $this->option('limit')));
        $messageLimit = max(1, min(100, (int) $this->option('messages')));
        $queued = 0;
        $previous = $context->id();
        $tenantIds = Tenant::query()->where('status', 'active')->orderBy('id')->pluck('id');

        try {
            foreach ($tenantIds as $tenantId) {
                if ($remaining < 1) {
                    break;
                }
                $context->set((int) $tenantId);
                $accounts = EmailAccount::query()->where('status', 'active')
                    ->whereHas('integration', fn ($query) => $query->where('status', 'active'))
                    ->orderBy('id')
                    ->get(['id', 'settings'])
                    ->filter(function (EmailAccount $account): bool {
                        $enabled = data_get($account->settings, 'sync_enabled', true);

                        return filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
                    })
                    ->take($remaining);
                foreach ($accounts as $account) {
                    SyncEmailAccountJob::dispatch((int) $tenantId, (int) $account->id, $messageLimit);
                    $queued++;
                    $remaining--;
                }
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }

        $this->info("Queued {$queued} email account sync job(s).");

        return self::SUCCESS;
    }
}
