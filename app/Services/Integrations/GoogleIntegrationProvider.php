<?php

namespace App\Services\Integrations;

use App\Models\Integration;

final class GoogleIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'google';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => 'Google',
            'description' => 'Valida una cuenta de Google mediante un token OAuth 2.0.',
            'fields' => [
                [
                    'key' => 'client_id', 'target' => 'credentials', 'label' => 'Client ID',
                    'type' => 'text', 'required' => false,
                ],
                [
                    'key' => 'client_secret', 'target' => 'credentials', 'label' => 'Client secret',
                    'type' => 'password', 'required' => false,
                ],
                [
                    'key' => 'access_token', 'target' => 'credentials', 'label' => 'Token de acceso OAuth',
                    'type' => 'password', 'required' => true,
                ],
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'credentials.client_id' => ['nullable', 'string', 'max:1000'],
            'credentials.client_secret' => ['nullable', 'string', 'max:4000'],
            'credentials.access_token' => ['required', 'string', 'max:10000'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $response = $this->request()
            ->withToken((string) data_get($integration->credentials, 'access_token'))
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        return $this->healthResult(
            $response->successful(),
            $response->status(),
            $response->successful() ? 'Conexión con Google verificada.' : 'Google rechazó las credenciales.',
        );
    }
}
