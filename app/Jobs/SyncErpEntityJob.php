<?php

namespace App\Jobs;

use App\Models\ErpSync;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Erp\ErpProviderManager;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncErpEntityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $tenantId, public readonly int $syncId) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->syncId;
    }

    public function handle(ErpProviderManager $providers): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        $sync = null;
        try {
            $sync = ErpSync::query()->with('integration')->findOrFail($this->syncId);
            if ($sync->status === 'completed') {
                return;
            }
            $attempt = (int) $sync->attempts + 1;
            $sync->update(['status' => 'processing', 'attempts' => $attempt, 'last_attempt_at' => now(), 'error' => null]);
            $provider = $providers->for($sync->integration->provider);
            $result = match ($sync->entity_type) {
                'product' => $provider->syncProduct($sync->integration, Product::query()->findOrFail($sync->entity_id), $sync->idempotency_key),
                'quote' => $provider->syncAcceptedQuote($sync->integration, Quote::query()->findOrFail($sync->entity_id), $sync->idempotency_key),
                default => throw new \RuntimeException('Unsupported ERP sync entity type.'),
            };
            $sync->logs()->create([
                'attempt' => $attempt, 'status' => 'completed', 'response_status' => $result['response_status'],
                'request_summary' => $result['request_summary'], 'response_summary' => $result['response_summary'],
            ]);
            $sync->update(['status' => 'completed', 'external_id' => $result['external_id'], 'completed_at' => now()]);
            if ($sync->entity_type === 'product') {
                Product::query()->whereKey($sync->entity_id)->update(['erp_product_id' => $result['external_id']]);
            } else {
                Quote::query()->whereKey($sync->entity_id)->update(['erp_document_id' => $result['external_id']]);
            }
        } catch (Throwable $exception) {
            if ($sync !== null) {
                $message = mb_substr($exception->getMessage(), 0, 1000);
                $sync->update(['status' => 'failed', 'error' => $message]);
                $sync->logs()->create(['attempt' => max(1, (int) $sync->attempts), 'status' => 'failed', 'error' => $message]);
            }
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
