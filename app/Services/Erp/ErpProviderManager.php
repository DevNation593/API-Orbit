<?php

namespace App\Services\Erp;

use App\Contracts\ErpProviderInterface;
use InvalidArgumentException;

final class ErpProviderManager
{
    /** @var array<string, ErpProviderInterface> */
    private array $providers = [];

    public function register(ErpProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function for(string $key): ErpProviderInterface
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("ERP provider [{$key}] is not registered.");
    }
}
