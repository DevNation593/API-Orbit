<?php

namespace App\Jobs;

use App\Models\WebhookInboundEvent;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $tenantId, public readonly int $eventId) {}

    public function handle(): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        $event = null;

        try {
            $event = WebhookInboundEvent::query()->findOrFail($this->eventId);
            if ($event->status === 'processed') {
                return;
            }

            $event->update([
                'status' => 'processed',
                'attempts' => $event->attempts + 1,
                'processed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $event?->update([
                'status' => 'failed',
                'attempts' => ($event->attempts ?? 0) + 1,
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
