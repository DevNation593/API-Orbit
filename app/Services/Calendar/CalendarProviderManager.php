<?php

namespace App\Services\Calendar;

use App\Contracts\CalendarProviderInterface;
use InvalidArgumentException;

final class CalendarProviderManager
{
    /** @var array<string, CalendarProviderInterface> */
    private array $providers = [];

    public function register(CalendarProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function for(string $provider): CalendarProviderInterface
    {
        return $this->providers[$provider]
            ?? throw new InvalidArgumentException("Calendar provider [$provider] is not registered.");
    }
}
