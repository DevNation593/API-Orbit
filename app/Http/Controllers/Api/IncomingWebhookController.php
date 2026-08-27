<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessIncomingWebhookJob;
use App\Models\IdempotencyKey;
use App\Models\WebhookEndpoint;
use App\Models\WebhookInboundEvent;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IncomingWebhookController
{
    public function receive(Request $request, int $endpointId, string $token): JsonResponse
    {
        $route = DB::table('webhook_ingress_routes')->where('endpoint_id', $endpointId)->first();
        abort_unless($route !== null, 404);

        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set((int) $route->tenant_id);
        try {
            $endpoint = WebhookEndpoint::query()->whereKey($endpointId)->where('active', true)->firstOrFail();
            $expectedToken = hash_hmac('sha256', (string) $endpointId, $endpoint->secret);
            abort_unless(hash_equals($expectedToken, $token), 404);

            $raw = $request->getContent();
            $signature = (string) $request->header('X-Webhook-Signature', '');
            abort_unless($signature !== '' && hash_equals(hash_hmac('sha256', $raw, $endpoint->secret), $signature), 401, 'Invalid webhook signature.');

            $idempotencyKey = $request->header('Idempotency-Key');
            abort_unless(is_string($idempotencyKey) && preg_match('/^[A-Za-z0-9._:-]{8,190}$/', $idempotencyKey), 422, 'Idempotency-Key is required.');

            $existing = IdempotencyKey::query()->where('key', $idempotencyKey)->first();
            abort_unless($existing === null || $existing->endpoint === 'incoming-webhook:'.$endpointId, 422, 'This idempotency key is already used for another endpoint.');
            if ($existing?->response_body !== null && $existing->expires_at->isFuture()) {
                return response()->json($existing->response_body, $existing->response_status ?? 202);
            }

            $payload = json_decode($raw, true);
            abort_unless(is_array($payload), 422, 'Webhook payload must be valid JSON.');
            $eventType = (string) $request->header('X-Webhook-Event', 'external.webhook');
            abort_unless(preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $eventType), 422, 'Webhook event is invalid.');
            $response = ['data' => ['accepted' => true], 'meta' => ['event' => $eventType]];
            $idempotencyData = [
                'user_id' => null,
                'key' => $idempotencyKey,
                'endpoint' => 'incoming-webhook:'.$endpointId,
                'response_status' => 202,
                'response_body' => $response,
                'expires_at' => now()->addDay(),
            ];
            $existing === null ? IdempotencyKey::create($idempotencyData) : $existing->update($idempotencyData);
            $inbound = WebhookInboundEvent::create([
                'endpoint_id' => $endpointId,
                'idempotency_key' => $idempotencyKey,
                'event' => $eventType,
                'payload' => $payload,
                'status' => 'queued',
            ]);
            ProcessIncomingWebhookJob::dispatch((int) $endpoint->tenant_id, (int) $inbound->id)->onQueue('integrations');
            Log::info('Incoming webhook accepted', ['tenant_id' => $endpoint->tenant_id, 'endpoint_id' => $endpointId, 'event' => $eventType]);

            return response()->json($response, 202);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
