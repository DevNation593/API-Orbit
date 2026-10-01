<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\ChannelWebhookEvent;
use App\Models\WhatsAppAccount;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless($mode === 'subscribe' && is_string($token) && is_scalar($challenge), 403, 'Webhook verification failed.');
        $account = WhatsAppAccount::query()->withoutGlobalScope('tenant')
            ->where('verify_token_hash', hash('sha256', $token))->where('status', 'active')->first();
        abort_if($account === null, 403, 'Webhook verification failed.');

        return response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload) && ($payload['object'] ?? null) === 'whatsapp_business_account', 400, 'Invalid WhatsApp webhook payload.');
        $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');
        abort_unless(is_scalar($phoneNumberId), 400, 'The WhatsApp phone number id is missing.');
        $account = WhatsAppAccount::query()->withoutGlobalScope('tenant')->with('integration')
            ->where('phone_number_id', (string) $phoneNumberId)->where('status', 'active')->firstOrFail();
        $secret = (string) data_get($account->integration?->credentials, 'app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        abort_unless($secret !== '' && hash_equals($expected, $signature), 401, 'Invalid WhatsApp webhook signature.');

        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set((int) $account->tenant_id);
        try {
            $dedupeKey = hash('sha256', $raw);
            $event = ChannelWebhookEvent::firstOrCreate([
                'provider' => 'whatsapp',
                'account_id' => $account->id,
                'dedupe_key' => $dedupeKey,
            ], [
                'event_type' => $this->eventType($payload),
                'payload' => $payload,
                'status' => 'pending',
            ]);
            if ($event->wasRecentlyCreated) {
                ProcessWhatsAppWebhookJob::dispatch((int) $account->tenant_id, (int) $account->id, (int) $event->id);
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }

        return response()->json(['received' => true, 'duplicate' => ! $event->wasRecentlyCreated]);
    }

    /** @param array<string, mixed> $payload */
    private function eventType(array $payload): string
    {
        if (data_get($payload, 'entry.0.changes.0.value.messages.0') !== null) {
            return 'message';
        }
        if (data_get($payload, 'entry.0.changes.0.value.statuses.0') !== null) {
            return 'status';
        }

        return 'unknown';
    }
}
