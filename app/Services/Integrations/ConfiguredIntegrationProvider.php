<?php

namespace App\Services\Integrations;

use App\Contracts\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ConfiguredIntegrationProvider implements IntegrationProvider
{
    /** @param array<string, mixed> $definition */
    public function __construct(
        private readonly string $providerKey,
        private readonly array $definition,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function definition(): array
    {
        $required = is_array($this->definition['required_credentials'] ?? null)
            ? $this->definition['required_credentials']
            : [];
        $fields = collect($this->definition['credential_fields'] ?? [])
            ->filter(fn (mixed $field): bool => is_array($field) && is_string($field['key'] ?? null))
            ->map(fn (array $field): array => [
                'key' => $field['key'],
                'target' => 'credentials',
                'label' => $field['label'] ?? $field['key'],
                'type' => ($field['secret'] ?? false) ? 'password' : 'text',
                'required' => in_array($field['key'], $required, true),
            ])
            ->values()
            ->all();

        return [
            'key' => $this->providerKey,
            'label' => $this->definition['label'] ?? $this->providerKey,
            'description' => $this->definition['description'] ?? '',
            'fields' => $fields,
        ];
    }

    public function validateConfiguration(array $credentials, array $settings): void
    {
        $integration = new Integration(['credentials' => $credentials, 'settings' => $settings]);
        $missing = $this->missingCredentials($integration);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'credentials' => ['Completa estas credenciales: '.implode(', ', $missing).'.'],
            ]);
        }
    }

    public function connect(Integration $integration): void
    {
        $this->validateConfiguration($integration->credentials ?? [], $integration->settings ?? []);
        throw new RuntimeException('Este proveedor no tiene un adaptador de conexión instalado.');
    }

    public function disconnect(Integration $integration): void
    {
        // No remote adapter is available for this catalog-only provider.
    }

    /** @return array<string, mixed> */
    public function health(Integration $integration): array
    {
        $missing = $this->missingCredentials($integration);

        return [
            'ok' => false,
            'status' => null,
            'configured' => $missing === [],
            'missing_credentials' => $missing,
            'checked_at' => now()->toIso8601String(),
            'message' => $missing === []
                ? 'Este proveedor no tiene un adaptador de conexión instalado.'
                : 'Faltan credenciales para conectar este proveedor.',
        ];
    }

    /** @return array<int, string> */
    private function missingCredentials(Integration $integration): array
    {
        $credentials = is_array($integration->credentials) ? $integration->credentials : [];
        $required = is_array($this->definition['required_credentials'] ?? null)
            ? $this->definition['required_credentials']
            : [];

        return array_values(array_filter(
            $required,
            fn (mixed $key): bool => ! is_string($key)
                || ! isset($credentials[$key])
                || trim((string) $credentials[$key]) === '',
        ));
    }
}
