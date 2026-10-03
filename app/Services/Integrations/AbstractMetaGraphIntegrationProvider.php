<?php

namespace App\Services\Integrations;

use App\Models\Integration;

abstract class AbstractMetaGraphIntegrationProvider extends AbstractIntegrationProvider
{
    abstract protected function label(): string;

    abstract protected function description(): string;

    abstract protected function objectSetting(): string;

    abstract protected function objectLabel(): string;

    abstract protected function objectTarget(): string;

    /** @return array<int, array<string, mixed>> */
    protected function additionalFields(): array
    {
        return [];
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'description' => $this->description(),
            'fields' => [
                [
                    'key' => 'access_token', 'target' => 'credentials', 'label' => 'Token de acceso',
                    'type' => 'password', 'required' => true,
                ],
                [
                    'key' => 'api_version', 'target' => 'settings', 'label' => 'Versión de Graph API',
                    'type' => 'text', 'required' => true, 'placeholder' => 'vXX.X',
                ],
                [
                    'key' => $this->objectSetting(), 'target' => $this->objectTarget(), 'label' => $this->objectLabel(),
                    'type' => 'text', 'required' => true,
                ],
                ...$this->additionalFields(),
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'credentials.access_token' => ['required', 'string', 'max:10000'],
            'settings.api_version' => ['required', 'string', 'regex:/^v[0-9]{1,3}\.[0-9]{1,2}$/'],
            $this->objectTarget().'.'.$this->objectSetting() => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,160}$/'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $version = (string) data_get($integration->settings, 'api_version');
        $objectId = (string) data_get(
            $this->objectTarget() === 'credentials' ? $integration->credentials : $integration->settings,
            $this->objectSetting(),
        );
        $response = $this->request()
            ->withToken((string) data_get($integration->credentials, 'access_token'))
            ->get("https://graph.facebook.com/{$version}/{$objectId}", ['fields' => 'id']);

        return $this->healthResult(
            $response->successful(),
            $response->status(),
            $response->successful() ? 'Conexión con '.$this->label().' verificada.' : $this->label().' rechazó la configuración.',
        );
    }
}
