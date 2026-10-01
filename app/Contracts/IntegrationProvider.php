<?php

namespace App\Contracts;

use App\Models\Integration;

interface IntegrationProvider
{
    public function key(): string;

    /** @return array<string, mixed> */
    public function definition(): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public function validateConfiguration(array $credentials, array $settings): void;

    public function connect(Integration $integration): void;

    public function disconnect(Integration $integration): void;

    /** @return array<string, mixed> */
    public function health(Integration $integration): array;
}
