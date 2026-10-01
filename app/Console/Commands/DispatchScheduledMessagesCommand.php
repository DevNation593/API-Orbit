<?php

namespace App\Console\Commands;

use App\Jobs\DeliverConversationMessageJob;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class DispatchScheduledMessagesCommand extends Command
{
    protected $signature = 'inbox:dispatch-scheduled {--limit=500 : Maximum messages to dispatch}';

    protected $description = 'Dispatch due scheduled inbox messages without losing tenant isolation';

    public function handle(TenantContext $context): int
    {
        $remaining = max(1, min(5000, (int) $this->option('limit')));
        $dispatched = 0;
        $previous = $context->id();

        try {
            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->cursor() as $tenant) {
                if ($remaining < 1) {
                    break;
                }
                $context->set((int) $tenant->id);
                $ids = Message::query()
                    ->where('status', 'scheduled')
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '<=', now())
                    ->orderBy('scheduled_at')
                    ->orderBy('id')
                    ->limit($remaining)
                    ->pluck('id');

                foreach ($ids as $messageId) {
                    $claimed = Message::query()->whereKey($messageId)
                        ->where('status', 'scheduled')
                        ->where('scheduled_at', '<=', now())
                        ->update(['status' => 'queued', 'updated_at' => now()]);
                    if ($claimed !== 1) {
                        continue;
                    }
                    DeliverConversationMessageJob::dispatch((int) $tenant->id, (int) $messageId);
                    $dispatched++;
                    $remaining--;
                }
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }

        $this->info("Dispatched {$dispatched} scheduled message(s).");

        return self::SUCCESS;
    }
}
