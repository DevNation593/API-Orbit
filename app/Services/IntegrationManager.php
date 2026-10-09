<?php

namespace App\Services;

use App\Contracts\IntegrationProvider;
use App\Models\Integration;
use App\Services\Integrations\ConfiguredIntegrationProvider;
use InvalidArgumentException;

final class IntegrationManager
{
    /** @var array<string, IntegrationProvider> */
    private array $providers = [];

    /** @param array<string, array<string, mixed>> $catalog */
    public function __construct(private readonly array $catalog = []) {}

    public function register(IntegrationProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function provider(string $key): IntegrationProvider
    {
        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        $definition = $this->catalog[$key] ?? null;
        if (is_array($definition)) {
            return new ConfiguredIntegrationProvider($key, $definition);
        }

        throw new InvalidArgumentException("Integration provider [$key] is not registered.");
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->catalog();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public function validateConfiguration(string $provider, array $credentials, array $settings): void
    {
        $this->provider($provider)->validateConfiguration($credentials, $settings);
    }

    public function connect(Integration $integration): void
    {
        $this->provider($integration->provider)->connect($integration);
    }

    public function disconnect(Integration $integration): void
    {
        $this->provider($integration->provider)->disconnect($integration);
    }

    /** @return array<int, array<string, mixed>> */
    public function catalog(): array
    {
        return collect(array_unique([...array_keys($this->catalog), ...array_keys($this->providers)]))
            ->map(fn (string $key): array => $this->provider($key)->definition())
            ->sortBy('label')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function health(Integration $integration): array
    {
        return $this->provider($integration->provider)->health($integration);
    }
}
