<?php

namespace App\Services\Integrations;

use App\Contracts\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

abstract class AbstractIntegrationProvider implements IntegrationProvider
{
    /** @return array<string, mixed> */
    abstract protected function checkHealth(Integration $integration): array;

    /** @return array<string, mixed> */
    abstract protected function configurationRules(): array;

    public function validateConfiguration(array $credentials, array $settings): void
    {
        $fields = collect($this->definition()['fields'] ?? []);
        $allowedCredentials = $fields->where('target', 'credentials')->pluck('key')->all();
        $errors = [];

        foreach (array_diff(array_keys($credentials), $allowedCredentials) as $key) {
            $errors['credentials.'.$key][] = 'This credential is not supported by the selected provider.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        Validator::make(
            ['credentials' => $credentials, 'settings' => $settings],
            $this->configurationRules(),
        )->validate();
    }

    public function connect(Integration $integration): void
    {
        $this->validateConfiguration($integration->credentials ?? [], $integration->settings ?? []);
        $health = $this->health($integration);
        if (! ($health['ok'] ?? false)) {
            throw new RuntimeException((string) ($health['message'] ?? 'The provider rejected the connection.'));
        }
    }

    public function disconnect(Integration $integration): void
    {
        // Credentials are removed locally when an integration is deleted.
    }

    final public function health(Integration $integration): array
    {
        try {
            $this->validateConfiguration(
                $integration->credentials ?? [],
                $integration->settings ?? [],
            );

            return $this->checkHealth($integration);
        } catch (ValidationException $exception) {
            return $this->healthResult(
                false,
                null,
                (string) collect($exception->errors())->flatten()->first('La configuración está incompleta.'),
            );
        } catch (Throwable) {
            return $this->healthResult(false, null, 'No se pudo contactar al proveedor.');
        }
    }

    protected function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(12)
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    /** @return array{ok: bool, status: ?int, message: string, checked_at: string} */
    protected function healthResult(bool $ok, ?int $status, string $message): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
