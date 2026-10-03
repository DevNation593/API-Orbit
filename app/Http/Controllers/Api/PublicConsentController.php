<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConsentService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PublicConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consent) {}

    public function show(Request $request, string $token): Response
    {
        return $this->handle($request, $token, false);
    }

    public function update(Request $request, string $token): Response
    {
        return $this->handle($request, $token, true);
    }

    private function handle(Request $request, string $token, bool $update): Response
    {
        $link = $this->consent->findLink($token);
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set((int) $link->tenant_id);
        try {
            if ($update) {
                $data = $request->validate([
                    'preferences' => ['required', 'array:email,sms,whatsapp', 'min:1'],
                    'preferences.*' => ['required', Rule::in(['opt_out'])],
                    'idempotency_key' => ['required', 'string', 'max:80'],
                ]);
                DB::transaction(function () use ($data, $link, $request): void {
                    foreach ($data['preferences'] as $channel => $status) {
                        $destination = $link->destinations[$channel] ?? null;
                        if ($destination === null) {
                            continue;
                        }
                        $this->consent->record([
                            'entity_type' => $link->entity_type, 'entity_id' => $link->entity_id,
                            'channel' => $channel, 'status' => $status, 'source' => 'preference_center',
                            'evidence' => 'Revocation through a valid preference link.',
                            'idempotency_key' => 'link:'.$link->id.':'.$data['idempotency_key'].':'.$channel,
                        ], $request->ip(), null, $destination);
                    }
                });
            }
            $preferences = collect(ConsentService::CHANNELS)->mapWithKeys(fn ($channel) => [
                $channel => $this->consent->status($channel, $link->destinations[$channel] ?? null),
            ])->all();

            $response = $request->expectsJson()
                ? ApiResponse::success(['preferences' => $preferences, 'expires_at' => $link->expires_at])
                : response()->view('marketing.preferences', [
                    'preferences' => $preferences, 'token' => $token, 'updated' => $update,
                    'idempotencyKey' => Str::random(32),
                    'available' => array_keys(array_filter($link->destinations)),
                ]);

            return $response
                ->header('X-Frame-Options', 'DENY')
                ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'")
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
