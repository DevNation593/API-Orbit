<?php

namespace App\Services\Integrations;

use App\Models\Integration;

final class MicrosoftIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'microsoft';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => 'Microsoft 365',
            'description' => 'Valida una cuenta de Microsoft mediante Microsoft Graph.',
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
            ->get('https://graph.microsoft.com/v1.0/me');

        return $this->healthResult(
            $response->successful(),
            $response->status(),
            $response->successful() ? 'Conexión con Microsoft verificada.' : 'Microsoft rechazó las credenciales.',
        );
    }
}
