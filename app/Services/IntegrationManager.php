<?php

namespace App\Services;

use App\Contracts\IntegrationProvider;
use App\Models\Integration;
use InvalidArgumentException;

final class IntegrationManager
{
    /** @var array<string, IntegrationProvider> */
    private array $providers = [];

    public function register(IntegrationProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function provider(string $key): IntegrationProvider
    {
        return $this->providers[$key]
            ?? throw new InvalidArgumentException("Integration provider [$key] is not registered.");
    }

    /** @return array<string, mixed> */
    public function health(Integration $integration): array
    {
        return $this->provider($integration->provider)->health($integration);
    }
}
