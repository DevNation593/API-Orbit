<?php

namespace App\Services\Integrations;

use App\Models\Integration;

final class ZoomIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'zoom';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => 'Zoom',
            'description' => 'Crea y administra reuniones de Zoom mediante OAuth.',
            'fields' => [
                [
                    'key' => 'access_token', 'target' => 'credentials', 'label' => 'Token de acceso OAuth',
                    'type' => 'password', 'required' => true,
                ],
                [
                    'key' => 'user_id', 'target' => 'settings', 'label' => 'Usuario de Zoom',
                    'type' => 'text', 'required' => false, 'placeholder' => 'me',
                ],
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'credentials.access_token' => ['required', 'string', 'max:10000'],
            'settings.user_id' => ['nullable', 'string', 'max:190'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $userId = (string) data_get($integration->settings, 'user_id', 'me');
        $response = $this->request()->withToken((string) data_get($integration->credentials, 'access_token'))
            ->get('https://api.zoom.us/v2/users/'.rawurlencode($userId));

        return $this->healthResult(
            $response->successful(),
            $response->status(),
            $response->successful() ? 'Conexión con Zoom verificada.' : 'Zoom rechazó las credenciales.',
        );
    }
}
