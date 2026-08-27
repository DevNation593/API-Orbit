<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\TenantContext;
use App\Support\UrlSafety;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $tenantId, public readonly int $deliveryId) {}

    public function handle(): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        $delivery = null;
        try {
            $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($this->deliveryId);
            if ($delivery->status === 'delivered') {
                return;
            }

            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'last_attempt_at' => now()]);
            if (! UrlSafety::isPublic($delivery->endpoint->url)) {
                throw new \RuntimeException('Webhook endpoint is not a public URL.');
            }
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-CRM-Event' => $delivery->event,
                    'X-CRM-Signature' => $delivery->signature,
                    'X-CRM-Delivery-ID' => (string) $delivery->id,
                ])
                ->post($delivery->endpoint->url, $delivery->payload);

            $delivery->update([
                'status' => $response->successful() ? 'delivered' : 'failed',
                'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 2000),
            ]);
            if (! $response->successful()) {
                throw new \RuntimeException('Webhook endpoint returned HTTP '.$response->status());
            }
        } catch (\Throwable $exception) {
            $delivery?->update([
                'status' => 'failed',
                'response_body' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
