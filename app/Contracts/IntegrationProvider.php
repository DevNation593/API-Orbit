<?php

namespace App\Contracts;

use App\Models\Integration;

interface IntegrationProvider
{
    public function key(): string;

    public function connect(Integration $integration): void;

    public function disconnect(Integration $integration): void;

    /** @return array<string, mixed> */
    public function health(Integration $integration): array;
}
