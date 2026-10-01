<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use Illuminate\Validation\ValidationException;

final class EmailProviderManager
{
    /** @var array<int, EmailProviderInterface> */
    private array $providers = [];

    public function register(EmailProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    public function for(string $provider): EmailProviderInterface
    {
        foreach ($this->providers as $candidate) {
            if ($candidate->supports($provider)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['provider' => "Email provider [$provider] is not supported."]);
    }
}
