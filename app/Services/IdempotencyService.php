<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class IdempotencyService
{
    public function replay(Request $request): ?JsonResponse
    {
        $key = $this->key($request);
        if ($key === null) {
            return null;
        }

        $endpoint = $request->method().':'.$request->path();
        $record = IdempotencyKey::query()->where('key', $key)->first();
        if ($record !== null && $record->endpoint !== $endpoint && $record->expires_at->isFuture()) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'This key is already used for another endpoint.']);
        }
        if ($record?->endpoint !== $endpoint || $record->expires_at->isPast()) {
            return null;
        }

        return $record?->response_body === null
            ? null
            : response()->json($record->response_body, $record->response_status ?? 200);
    }

    public function remember(Request $request, JsonResponse $response): void
    {
        $key = $this->key($request);
        if ($key === null) {
            return;
        }

        $endpoint = $request->method().':'.$request->path();
        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing !== null && $existing->endpoint !== $endpoint) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'This key is already used for another endpoint.']);
        }

        IdempotencyKey::updateOrCreate(
            ['key' => $key, 'endpoint' => $endpoint],
            [
                'user_id' => $request->user()?->id,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
                'expires_at' => now()->addHours(24),
            ],
        );
    }

    private function key(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,190}$/', $key) ? $key : null;
    }
}
