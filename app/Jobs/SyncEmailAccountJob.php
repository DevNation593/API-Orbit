<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\Email\EmailInboundService;
use App\Services\Email\EmailProviderManager;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncEmailAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $accountId,
        public readonly int $limit = 100,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(
        EmailProviderManager $providers,
        EmailInboundService $inbound,
        AuditService $audit,
        TenantContext $context,
    ): void {
        $lock = Cache::lock('email-account-sync:'.$this->tenantId.':'.$this->accountId, 240);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        $previous = $context->id();
        $account = null;
        try {
            $context->set($this->tenantId);
            $account = EmailAccount::with(['integration', 'channel.inbox'])->findOrFail($this->accountId);
            if ($account->status !== 'active' || $account->integration?->status !== 'active') {
                return;
            }
            $batch = $providers->for($account->provider)->sync($account, $this->limit);
            $count = $inbound->ingest($account, $batch['messages']);
            $settings = $account->settings ?? [];
            unset($settings['last_sync_error'], $settings['last_sync_failed_at']);
            $settings['last_sync_count'] = $count;
            $settings['sync_has_more'] = (bool) $batch['has_more'];
            $account->update([
                'sync_cursor' => $batch['cursor'],
                'last_synced_at' => now(),
                'settings' => $settings,
            ]);
            $account->integration->update(['last_synced_at' => now()]);
            $audit->record('email_sync', $account, newValues: [
                'provider' => $account->provider,
                'message_count' => $count,
                'has_more' => (bool) $batch['has_more'],
            ]);
        } catch (Throwable $exception) {
            if ($account !== null) {
                $settings = array_replace($account->settings ?? [], [
                    'last_sync_error' => mb_substr($exception->getMessage(), 0, 500),
                    'last_sync_failed_at' => now()->toISOString(),
                ]);
                $account->update(['settings' => $settings]);
            }
            throw $exception;
        } finally {
            $lock->release();
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
